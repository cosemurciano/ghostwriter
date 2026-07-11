# Ghostwriter — Architettura del plugin v1.0

Documento di architettura per lo sviluppo. Si legge insieme al `README.md` (contratto dati e macchine a stati) e agli schemi in `schemas/`. Le regole sono: l'AI produce solo il formato intermedio; il PHP compila e renderizza; ogni operazione lunga è un job idempotente in coda; ogni entità ha uno stato persistito.

## 1. Requisiti e dipendenze

Il plugin richiede PHP 8.1+ e WordPress 6.4+. Dipendenze Composer (vendorizzate con prefixing via PHP-Scoper per evitare conflitti con altri plugin che caricano le stesse librerie):

| Pacchetto | Uso | Note |
|---|---|---|
| `mpdf/mpdf` | Rendering PDF | **Riuso da BookCreator**: portare versione, patch e configurazione font già collaudate |
| `phpzip/phpzip` o ZipArchive | Pacchetti ePub e temi | ZipArchive nativo è sufficiente |
| ePub builder | Rendering ePub | Da BookCreator se PHPePub è già integrato; in alternativa builder interno EPUB3 (è XHTML+zip, il controllo diretto paga sui fallback) |
| `woocommerce/action-scheduler` | Coda job | Caricata come libreria, non richiede WooCommerce |
| `justinrainbow/json-schema` | Validazione schemi | Valida config, contenuti, temi a ogni scrittura/import |
| `ramsey/uuid` | ID blocchi | O `wp_generate_uuid4()` |

Client API Anthropic/OpenAI: implementati internamente su `wp_remote_post` (niente SDK: meno superficie, streaming non necessario in background).

## 2. Struttura del plugin

```
ghostwriter/
├── ghostwriter.php                  # bootstrap, autoload, requirements check
├── composer.json
├── src/
│   ├── Core/
│   │   ├── Plugin.php               # service container, registrazione hook
│   │   ├── Activator.php            # tabelle custom, ruoli/capability
│   │   ├── PostTypes.php            # gw_project, gw_chapter
│   │   └── Capabilities.php         # gw_manage_projects, gw_approve, gw_export
│   ├── Domain/
│   │   ├── Project.php              # aggregato: config + dossier + stato
│   │   ├── Chapter.php              # contenuto strutturato + stato
│   │   ├── StateMachine.php         # transizioni valide, eventi
│   │   ├── Dossier.php              # lettura/aggiornamento atomico
│   │   ├── BlockRevisionService.php # versioning blocchi: archivia/riscrivi/ripristina (§5.1)
│   │   └── SourceRegistry.php       # fonti, licenze, citazioni
│   ├── Repository/
│   │   ├── ProjectRepository.php    # CPT+meta <-> Domain (unico punto I/O)
│   │   ├── ChapterRepository.php
│   │   └── UsageRepository.php      # tabella gw_usage
│   ├── Queue/
│   │   ├── Dispatcher.php           # accoda job con dedup key
│   │   ├── JobInterface.php         # handle(), dedupKey(), onFailure()
│   │   └── Jobs/                    # un file per job, vedi §5
│   ├── Ai/
│   │   ├── ProviderInterface.php    # complete(), generateImage(), ...
│   │   ├── AnthropicProvider.php
│   │   ├── OpenAiProvider.php
│   │   ├── ContextComposer.php      # costruisce il contesto per fase (§6)
│   │   ├── SkillsManager.php        # registry locale + sync remoto + versioni
│   │   ├── RagService.php           # vector store per progetto, ingestione, query
│   │   └── UsageMeter.php           # token/costi/immagini, budget cap
│   ├── Media/
│   │   ├── ImageService.php         # brief -> immagine -> Media Library
│   │   └── CoverComposer.php        # artwork + tipografia (GD/Imagick + template)
│   ├── Rendering/
│   │   ├── ThemeRegistry.php        # import zip, validazione, versioni
│   │   ├── ThemeCompiler/
│   │   │   ├── MpdfCssCompiler.php  # theme.json -> CSS mPDF (@page, mirror margini)
│   │   │   └── EpubCssCompiler.php  # theme.json -> CSS safe-subset + fallback
│   │   ├── BlockRenderer.php        # formato intermedio -> HTML per target
│   │   ├── PdfExporter.php          # orchestrazione mPDF (riuso BookCreator)
│   │   ├── EpubExporter.php
│   │   └── Preflight.php            # validazioni pre-export (§8)
│   ├── Translation/
│   │   ├── DerivedProjectFactory.php # clona gerarchia, crea progetto derivato
│   │   └── GlossaryService.php
│   ├── Rest/
│   │   └── ...                      # endpoint REST (§9)
│   └── Admin/
│       └── ...                      # pagine admin (§10)
├── templates/                        # frammenti HTML admin
├── themes-bundled/                   # 1-2 temi di serie
└── assets/                           # css/js admin
```

Namespace radice `Ghostwriter\`, autoload PSR-4, service container minimale (array di factory in `Plugin.php`, niente framework).

## 3. Persistenza

**CPT e meta** come da README. I meta chiave, tutti validati contro gli schemi in scrittura:

| Meta | Entità | Schema |
|---|---|---|
| `_gw_config` | gw_project | project-config.schema.json |
| `_gw_dossier` | gw_project | dossier.schema.json |
| `_gw_state` | gw_project / gw_chapter | enum della state machine + timestamp transizioni |
| `_gw_content` | gw_chapter | chapter-content.schema.json |
| `_gw_project_id` | gw_chapter | int |

**Tabelle custom** (create in `Activator`, con `dbDelta`):

```sql
gw_usage (
  id BIGINT PK, project_id BIGINT, job VARCHAR(64),
  provider VARCHAR(32), model VARCHAR(64),
  input_tokens INT, output_tokens INT, images INT,
  cost_estimate DECIMAL(10,4), created_at DATETIME,
  INDEX (project_id, created_at)
)

gw_log (
  id BIGINT PK, project_id BIGINT, chapter_id BIGINT NULL,
  level VARCHAR(16), event VARCHAR(64), context JSON, created_at DATETIME,
  INDEX (project_id, created_at)
)

gw_block_revisions (
  id BIGINT PK, chapter_id BIGINT, block_id CHAR(36),
  version INT, origin VARCHAR(16),           -- ai_draft|ai_review|ai_rewrite|manual_edit|restore
  block JSON,                                 -- snapshot conforme a block-revision.schema.json
  feedback TEXT NULL, restored_from_version INT NULL,
  generated_with JSON NULL, author_user_id BIGINT NULL,
  created_at DATETIME,
  UNIQUE KEY (block_id, version), INDEX (chapter_id)
)
```

`gw_usage` alimenta il budget cap e il pannello costi; `gw_log` è il diario tecnico della pipeline (ogni transizione di stato, ogni chiamata AI con esito, ogni retry). I file esportati (PDF/ePub) vanno in `wp-content/uploads/ghostwriter/{project_id}/` con protezione via `.htaccess` + download autenticato.

**Segreti**: le API key si salvano cifrate (sodium, chiave derivata da `AUTH_KEY`/`SECURE_AUTH_KEY`), mai in chiaro in `wp_options`, mai nei log.

## 4. State machine

`StateMachine.php` è una classe unica, configurata con le mappe di transizione del README (progetto, capitolo, copertina, traduzione). Regole d'implementazione: ogni transizione passa da `StateMachine::transition($entity, $event)` che valida, persiste, scrive su `gw_log` e lancia `do_action('gw_state_changed', ...)`; nessun job scrive stati direttamente; le transizioni sono le uniche a poter accodare il job successivo (la pipeline avanza per eventi, non per orchestratori monolitici). Il superamento budget è un guard trasversale: `UsageMeter` lancia l'evento `budget_exceeded` che porta il progetto in `paused_budget` e sospende l'accodamento.

## 5. Job della pipeline

Ogni job implementa `JobInterface`. Regole comuni: **dedup key** = `{job}:{project_id}:{chapter_id}:{fase}` (Action Scheduler scarta i duplicati); **idempotenza** = il job controlla lo stato in ingresso e, se il lavoro risulta già fatto, esce senza effetti; **retry** = 3 tentativi con backoff esponenziale, poi stato `failed` con motivo in `gw_log`; **timeout** = ogni job fa una sola chiamata AI (mai loop di chiamate in un job: si spezza in job successivi).

| Job | Input | Output / effetti |
|---|---|---|
| `IngestSourcesJob` | project_id, source_id | Estrae testo (riuso parser da BookCreator per PDF), carica nel vector store, aggiorna registry |
| `ProposeOutlineJob` | project_id | Chiama AI (fase outline) con brief + panoramica fonti; scrive outline proposto nel dossier; stato → `outline_proposed` |
| `MaterializeChaptersJob` | project_id | Dopo approvazione: crea i gw_chapter gerarchici con brief nei meta |
| `DraftChapterJob` | chapter_id | Contesto §6 → contenuto strutturato validato → `_gw_content`; stato → `draft_ready` |
| `SynopsisJob` | chapter_id | Sinossi 100-200 parole + aggiornamenti continuità → dossier (lock ottimistico, vedi sotto) |
| `IndexChapterJob` | chapter_id | Indicizza il capitolo nel vector store (se attivo) |
| `ReviewChapterJob` | chapter_id | Fase review (skills di revisione, eventuale web search); produce contenuto revisionato |
| `RewriteBlockJob` | chapter_id, block_id, feedback | Vedi §5.1: archivia versione corrente → chiama AI con feedback e contesto locale → nuova versione del blocco |
| `GenerateImageJob` | chapter_id, block_id | `image_brief` → immagine (risoluzione dal formato progetto) → Media Library → `attachment_id` nel blocco |
| `CoverBriefJob` / `CoverArtworkJob` / `CoverComposeJob` | project_id | Pipeline copertina; la composizione è locale (no AI) |
| `ProposeGlossaryJob` | derived_project_id | Estrae terminologia dal dossier sorgente, propone rese |
| `TranslateChapterJob` | derived_chapter_id | Traduce blocco per blocco (mapping su block id), glossario in contesto |
| `ExportJob` | project_id, theme_id, target | Preflight → compilazione CSS → rendering → file in uploads |

### 5.1 Versioning e riscrittura dei blocchi

Ogni blocco è versionato individualmente: `id` (UUID) è stabile per sempre, `version` (int nel formato intermedio) si incrementa a ogni modifica. Le versioni precedenti non si perdono mai: vivono in `gw_block_revisions` come snapshot completi (schema `block-revision.schema.json`). La classe responsabile è `Domain/BlockRevisionService.php`, unico punto autorizzato a modificare un blocco:

```
rewrite(chapter_id, block_id, feedback, user_id):
  1. archivia il blocco corrente in gw_block_revisions (origin dalla provenienza)
  2. accoda RewriteBlockJob con il feedback
RewriteBlockJob:
  3. contesto = skills di stile (fase draft/review) + dossier (solo brief e continuità)
       + blocchi adiacenti (prev/next, per il raccordo) + blocco corrente + FEEDBACK utente
  4. output = il SOLO blocco riscritto, stesso id e stesso type, validato contro lo schema
  5. version++ nel _gw_content, log su gw_log
```

Regole: il feedback dell'utente ("troppo tecnico", "manca la fonte X", "accorcia") viene **conservato nella revisione** — è la traccia del perché delle modifiche e, aggregato nel tempo, diventa materiale prezioso per affinare le skills di stile. La riscrittura non può cambiare il `type` del blocco (per trasformare un paragrafo in box si elimina e si crea, scelta editoriale esplicita). Il **ripristino** di una versione vecchia non riavvolge la storia: crea una nuova versione con `origin: restore` e `restored_from_version` — storia sempre lineare, mai riscritta. Anche le **modifiche manuali** dall'editor passano dal servizio (origin `manual_edit`), così la cronologia è completa a prescindere da chi modifica. La riscrittura è ammessa negli stati capitolo da `draft_ready` a `complete` e non regredisce lo stato; se il capitolo è `complete` e la riscrittura è sostanziale, l'utente può richiedere il refresh della sinossi nel dossier (flag `refresh_synopsis` sul job, che accoda un `SynopsisJob`). Nei progetti di traduzione la riscrittura opera sul blocco tradotto con lo stesso meccanismo; il mapping con il sorgente resta valido perché l'`id` non cambia. La UI espone per ogni blocco: "richiedi riscrittura" con campo feedback, cronologia versioni con diff testuale e ripristino.

**Concorrenza sul dossier**: il dossier è scritto da più job (SynopsisJob di capitoli paralleli). `Dossier.php` usa lock ottimistico: legge `updated_at`, applica il merge (le sezioni sono per-capitolo, quindi il merge è naturale), riscrive solo se `updated_at` invariato, altrimenti rilegge e riapplica. In alternativa, si serializza: i `SynopsisJob` girano su un gruppo Action Scheduler a concorrenza 1 — più semplice, consigliato per la v1. Di default la generazione capitoli è comunque **sequenziale** (la continuità lo richiede: il capitolo N legge la sinossi dell'N-1); il parallelismo è ammesso solo per immagini e traduzioni.

## 6. Agent layer

**ProviderInterface** espone il minimo indispensabile:

```php
interface ProviderInterface {
    /** @return AiResult{content: array, usage: Usage} */
    public function complete(AiRequest $req): AiResult;   // structured output (JSON)
    public function generateImage(ImageRequest $req): ImageResult;
    public function ensureVectorStore(string $name): string;      // -> store id
    public function ingest(string $storeId, FilePayload $file): void;
    public function query(string $storeId, string $q, int $k): array;
    public function syncSkill(SkillBundle $b): SkillRef;          // upload/versione
}
```

Il **contratto di output** verso l'AI è sempre: "rispondi solo con JSON conforme a questo schema" (si passa lo schema del formato intermedio, o sua porzione, nel prompt; con OpenAI si usano gli structured outputs nativi, con Anthropic il tool-use forzato). La risposta viene validata con `json-schema`; se invalida → un solo retry con l'errore di validazione nel prompt, poi `failed`.

**ContextComposer** costruisce il contesto per fase secondo la ricetta del README (skills → dossier → brief capitolo → capitolo precedente → RAG). Le parti stabili (skills, istruzioni di sistema) vanno in testa e in blocchi separati per sfruttare il **prompt caching** di entrambi i provider. Il testo proveniente dalle fonti è **dato non fidato**: viene delimitato esplicitamente nel prompt come materiale da citare e mai come istruzioni da eseguire (mitigazione prompt injection da fonti open data / PDF di terzi).

**SkillsManager**: le skills vivono come bundle locali in `wp-content/uploads/ghostwriter/skills/{skill}/{version}/` (SKILL.md + asset), con un registry in opzione. Per OpenAI il manager sincronizza il bundle via `POST /v1/skills` e conserva lo `skill_id` remoto; per Anthropic il contenuto della skill viene composto nel contesto dal ContextComposer. Stessa sorgente, due strategie di consegna — il resto del sistema non se ne accorge.

## 7. Rendering

**BlockRenderer** trasforma il formato intermedio in HTML con classi stabili (`gw-block gw-paragrafo`, `gw-block gw-citazione gw-display-pull`, ...). Due profili: `pdf` (HTML pieno per mPDF: tabelle, float, note a piè pagina con la sintassi mPDF) e `epub` (XHTML valido, fallback applicati: tabelle larghe → impilate se `epub_fallback: stacked`, pull quote → blockquote, box con sfondo → bordo).

**MpdfCssCompiler** genera da `theme.json`: `@page` con formato dal progetto, margini con mirror interno/esterno (`@page :odd` / `:even`), header/footer con i placeholder risolti, `chapter_start` (recto con pagina bianca via `pagebreak` condizionale), stili per classe di blocco dai tokens, sillabazione, widows/orphans, TOC. **EpubCssCompiler** emette solo il sottoinsieme sicuro (test di riferimento: Kindle Previewer, Apple Books, Thorium/ADE) e rispetta `respect_reader_settings`. Output di entrambi in cache: `uploads/ghostwriter/cache/{theme}-{version}-{target}-{format}.css`.

**PdfExporter** è il punto di massimo riuso da BookCreator: configurazione mPDF (font custom, memoria, temp dir), generazione TOC e segnalibri, frontespizio/colophon dai frammenti del tema, inserzione copertina composta. **CoverComposer** compone la tipografia sopra l'artwork con Imagick (fallback GD) usando i frammenti `cover_composition` del tema; per il PDF print-ready produce la stesa completa (retro + dorso calcolato + fronte + abbondanza).

## 8. Preflight (pre-export)

`Preflight.php` esegue e riporta in UI, bloccando l'export in caso di errori: compatibilità formato progetto ↔ tema; copertura `structural_profile.allowed_blocks` ⊆ `theme.supports_blocks`; tutte le figure risolte (nessun `attachment_id` null) e risoluzione sufficiente per il formato; tutte le promesse del dossier `fulfilled`; note referenziate esistenti; fonti con `attribution_required` presenti in bibliografia; font embeddabili se `epub.embed_fonts`; validazione schema di tutti i capitoli.

## 9. REST API (namespace `ghostwriter/v1`)

Consumo interno dall'admin (nonce + capability), utile in futuro per automazioni esterne (application passwords).

```
POST   /projects                          crea progetto (config validata)
GET    /projects/{id}                     stato + dossier + costi
POST   /projects/{id}/sources             registra fonte + accoda ingestione
POST   /projects/{id}/outline/propose     accoda ProposeOutlineJob
PUT    /projects/{id}/outline             modifica outline proposto
POST   /projects/{id}/outline/approve     approva -> materializza capitoli
POST   /chapters/{id}/generate            accoda pipeline capitolo
POST   /chapters/{id}/approve             (se checkpoint per capitolo attivo)
POST   /chapters/{id}/blocks/{block_id}/rewrite   {feedback, refresh_synopsis?}
GET    /chapters/{id}/blocks/{block_id}/versions  cronologia (snapshot + feedback)
POST   /chapters/{id}/blocks/{block_id}/restore   {version} -> nuova versione
POST   /projects/{id}/cover/...           brief / regenerate / approve
POST   /projects/{id}/derive              crea progetto traduzione {language}
PUT    /projects/{id}/glossary            edita + approva glossario
POST   /projects/{id}/export              {theme_id, target: pdf|epub} -> job
GET    /projects/{id}/exports             elenco file generati (URL firmati)
GET    /projects/{id}/usage               costi e consumi
POST   /themes/import                     upload zip tema
GET    /themes                            registry con formati/blocchi supportati
POST   /skills/import  ·  GET /skills     registry skills
```

## 10. Admin UI

Interfaccia in pagine admin classiche + componenti JS leggeri (Alpine.js, coerente con lo stack che già usi) che interrogano la REST API. Schermate: **Progetti** (elenco con stato, avanzamento, costo corrente/budget); **Progetto** a tab — Configurazione (form generato dallo schema), Fonti (registry + stato ingestione), Indice (albero editabile drag&drop su `menu_order`/`post_parent`, approvazione), Capitoli (stati pipeline, anteprima HTML dal `post_content`, azioni rigenera/approva), Copertina (brief, anteprime, approvazione), Traduzioni (progetti derivati + glossario), Export (tema, target, preflight report, download), Costi (da `gw_usage`); **Temi** e **Skills** (import zip, versioni); **Impostazioni** (provider, chiavi cifrate, default). L'editing manuale di un capitolo avviene su una vista dedicata che modifica i blocchi (non il `post_content`), con revalidazione dello schema al salvataggio.

## 11. Sicurezza

Capability dedicate (`gw_manage_projects`, `gw_approve_content`, `gw_manage_settings`) assegnate ad Administrator ed eventuale ruolo Editor-Ghostwriter; nonce su tutte le mutazioni; sanitizzazione in ingresso e validazione schema in scrittura; escaping in uscita nell'admin; chiavi API cifrate (§3); contenuto delle fonti trattato come non fidato nel prompt (§6); file generati non listabili e serviti con controllo capability; gli zip di temi/skills importati vengono estratti in staging, validati (schema + niente PHP nei bundle) e solo poi registrati.

## 12. Riuso da BookCreator

| Da portare | Dove finisce | Note |
|---|---|---|
| Integrazione mPDF (config, font, memoria, TOC) | `PdfExporter` | Il pezzo di maggior valore: già collaudato |
| Integrazione PHPePub | `EpubExporter` | Verificare stato manutenzione; incapsulare dietro interfaccia per poterla sostituire |
| Estrazione testo da PDF | `IngestSourcesJob` | Se presente |
| Gestione Media Library / dimensioni immagini | `ImageService` | |
| Lezioni apprese su limiti mPDF | `MpdfCssCompiler` | Codificare i workaround noti come regole del compilatore |

Non si porta: il designer Konva, il modello dati precedente, ogni rendering non mediato dal formato intermedio.

## 13. Testing e qualità

Unit test (PHPUnit + Brain Monkey) su: StateMachine (transizioni valide/invalide), validazione schemi, ContextComposer (composizione e delimitazione fonti), compilatori CSS (snapshot test: theme.json → CSS atteso), BlockRenderer (fixture di capitoli → HTML atteso per entrambi i profili), merge del dossier. Integration test su un progetto fixture end-to-end con provider mock. Golden files: il capitolo d'esempio del pacchetto (`examples/chapter.example.json`) è il primo fixture. Per i PDF: test visivi manuali su checklist (mirror margini, recto, note, TOC) — l'output mPDF non si snapshotta in modo affidabile.

## 14. Ordine di sviluppo consigliato

1. **Fondamenta**: bootstrap, CPT, capability, tabelle, StateMachine, repository, validazione schemi
2. **Rendering prima dell'AI**: ThemeRegistry + compilatori + BlockRenderer + PdfExporter (porting mPDF) + EpubExporter, testati sul capitolo d'esempio — così il valore del porting da BookCreator si verifica subito e ogni output AI successivo ha già una destinazione
3. **Coda e pipeline**: Action Scheduler, Dispatcher, job con provider mock
4. **Agent layer**: provider reali, ContextComposer, SkillsManager, RagService, UsageMeter
5. **Pipeline complete**: outline → capitoli → immagini → dossier
6. **Copertina** e **preflight/export** end-to-end
7. **Traduzioni** (progetti derivati + glossario)
8. **Admin UI** rifinita (le schermate minime nascono insieme ai punti 3-6)

Il punto 2 prima del 4 è deliberato: separa il rischio "il rendering funziona?" (noto, mitigato dal riuso) dal rischio "l'AI produce contenuto valido?" (nuovo), e permette di sviluppare e testare i due fronti in parallelo se il lavoro viene distribuito.
