# Ghostwriter — Contratto dati v1.0

Specifica del contratto dati del plugin **Ghostwriter**: produzione di libri completi (PDF + ePub) in WordPress, con AI in background, skills condivise, fonti open data con RAG dedicato per progetto, copertine, traduzioni.

Questo documento definisce le entità, i quattro schemi JSON e le macchine a stati. È il documento da cui discende l'implementazione: ogni componente del plugin consuma o produce uno di questi formati, mai altro.

## Principio architetturale

L'AI produce **il formato intermedio** (blocchi tipizzati), mai il PDF o l'HTML finale. Il rendering è compito del PHP (mPDF / PHPePub), deterministico e debuggabile. L'intelligenza dove serve intelligenza, il codice dove serve affidabilità.

```
                    ┌─────────────────────────────────────────┐
                    │  SKILLS (condivise, versionate)         │
                    │  stile · genere · fonti · traduzione    │
                    └───────────────┬─────────────────────────┘
                                    │ montate per fase
  FONTI ──► VECTOR STORE ──►  ┌─────▼──────┐      ┌──────────────┐
  (registry con licenze)      │   AGENT    │◄────►│   DOSSIER    │
                              │ (pipeline) │      │ (continuità) │
                              └─────┬──────┘      └──────────────┘
                                    │ produce
                        ┌───────────▼────────────┐
                        │   FORMATO INTERMEDIO   │  ◄── unità: capitolo
                        │   (blocchi tipizzati)  │      (CPT gw_chapter)
                        └───────────┬────────────┘
                                    │ compilato da PHP
                     ┌──────────────┼──────────────┐
                ┌────▼────┐    ┌────▼────┐    ┌────▼─────┐
                │   PDF   │    │  ePub   │    │ Copertina│
                │ (mPDF + │    │(PHPePub │    │ (artwork │
                │  tema)  │    │ + tema) │    │  + tipo- │
                └─────────┘    └─────────┘    │  grafia) │
                                              └──────────┘
```

## Entità WordPress

| Entità | Implementazione | Note |
|---|---|---|
| Progetto (libro) | CPT `gw_project` | Config nel meta `_gw_config` (project-config.schema.json). Dossier nel meta `_gw_dossier`. |
| Capitolo | CPT `gw_chapter`, `hierarchical => true` | Gerarchia via `post_parent` (parte/capitolo/sottocapitolo), ordine via `menu_order`. Contenuto strutturato nel meta `_gw_content` (chapter-content.schema.json); `post_content` = resa HTML di sola anteprima. Legame al progetto: meta `_gw_project_id`. |
| Tema grafico | Registry interno + zip importati | `theme.json` (theme.schema.json) + `fonts/` + `assets/` + `pages/`. CSS compilato in cache per (tema, versione, target). |
| Skill | Registry interno | Riferimenti a skills su provider AI + copia locale del bundle. Versione bloccata per progetto. |
| Coda | Action Scheduler | Job idempotenti, riprendibili, con retry. Mai WP-Cron nudo. |
| Traduzione | Nuovo `gw_project` con `derived_from` | Clona gerarchia capitoli 1:1; traduce blocco per blocco (gli `id` dei blocchi sono la chiave di mapping). |

## I quattro schemi

1. **`schemas/project-config.schema.json`** — configurazione di progetto: lingua, formato fisico (fissato all'avvio: vincola copertina e risoluzione immagini), profilo strutturale (blocchi ammessi), skills montate con versione e fasi, fonti + vector store dedicato, provider e budget, checkpoint umani, cover package.

2. **`schemas/chapter-content.schema.json`** — il formato intermedio, contratto centrale. Blocchi tipizzati con `id` stabile (chiave per traduzioni e revisioni), provenienza fonti per blocco (`sources[]` → registry del progetto: bibliografia e attribuzioni CC-BY generate dai metadati, mai a memoria del modello), figure come placeholder con `image_brief` (scritte in fase draft, generate in fase images).

3. **`schemas/dossier.schema.json`** — la memoria di continuità. Passato a ogni chiamata al posto del libro intero: brief + indice con stati e sinossi (100-200 parole per capitolo completato) + registro di continuità (terminologia, concetti coperti, promesse al lettore, decisioni stilistiche) + glossario (solo traduzioni).

4. **`schemas/block-revision.schema.json`** — versioning per singolo blocco. Ogni riscrittura (richiesta all'AI con feedback dell'utente su cosa non va, o modifica manuale) archivia la versione corrente in `gw_block_revisions` prima di scrivere la nuova; il feedback è conservato con la revisione; il ripristino crea una nuova versione (storia lineare, mai riscritta). L'`id` del blocco resta stabile: solo `version` avanza.

5. **`schemas/theme.schema.json`** — tema grafico dichiarativo importabile. Design tokens + mapping sui blocchi + direttive per pagina (solo PDF). Compilato in due CSS: mPDF completo, ePub solo sottoinsieme sicuro con `epub_fallback` espliciti. Copertura blocchi validata contro il profilo strutturale del progetto **prima** dell'export.

## Composizione del contesto per la generazione di un capitolo

Ogni job "genera capitolo N" costruisce il contesto così (costo quasi costante):

```
skills montate per la fase (versione bloccata)
+ dossier completo
+ brief del capitolo N (dal dossier/outline)
+ testo integrale del SOLO capitolo precedente (per la transizione)
+ passaggi rilevanti dal vector store del progetto (fonti + capitoli già indicizzati)
```

## Macchine a stati

### Progetto
```
setup ─► sources_ingesting ─► outline_proposed ─► outline_approved ─► generating
   ─► review ─► cover_pending ─► ready_to_export ─► exported
                                        │
        paused_budget ◄─── (da qualunque stato se budget superato)
```

### Capitolo
```
planned ─► drafting ─► draft_ready ─► in_review ─► revised
      ─► images_pending ─► complete
                │
             failed ─► (retry dal passo fallito: ogni passo è idempotente)
```

L'ultimo passo di `draft_ready` genera la **sinossi** e aggiorna il dossier (terminologia, promesse). Se `index_chapters_in_vector_store` è attivo, il capitolo completato viene indicizzato nel vector store del progetto.

### Copertina
```
pending ─► brief_ready ─► artwork_ready ─► composed ─► approved
```
L'artwork è **sempre senza testo**: la tipografia la compone il plugin dai frammenti `special_pages.cover_composition` del tema. Conseguenza: la copertina della traduzione si ricompone da sola col titolo tradotto.

### Traduzione (progetto derivato)
```
setup ─► glossary_proposed ─► glossary_approved ─► translating (per capitolo)
    ─► review ─► ready_to_export
```

## Checkpoint umani

| Checkpoint | Default | Perché |
|---|---|---|
| Outline | obbligatorio | Governa tutta la spesa a valle; l'approvazione materializza i `gw_chapter` |
| Glossario | obbligatorio (traduzioni) | Coerenza terminologica capitolo 1 → N |
| Capitolo per capitolo | opzionale | Per progetti ad alto valore |
| Copertina | attivo | Approvazione visiva |
| Pre-export | attivo | Ultima verifica (promesse mantenute, figure risolte, validazione tema/profilo) |

## Regole trasversali

- **Versioni bloccate**: skills e temi si referenziano sempre con versione esplicita nei progetti avviati. Aggiornare una skill a metà libro è una scelta consapevole, non un effetto collaterale.
- **Provenienza obbligatoria** dove il contenuto deriva da fonti: ogni blocco derivato porta `sources[]`; la bibliografia si genera dal registry, mai dalla memoria del modello.
- **Web search** attiva solo nelle fasi dichiarate in `sources.web_search_phases` (default: solo review).
- **Budget cap**: contatore token/immagini/costo per progetto; superamento → `paused_budget`, mai job silenziosamente costosi.
- **Idempotenza**: ogni job può essere rieseguito senza duplicare effetti (chiavi di dedup su chapter_id + fase).

## Implementazione

L'architettura completa del plugin (struttura del codice, job della pipeline, agent layer, compilatori, REST API, admin UI, sicurezza, riuso da BookCreator, ordine di sviluppo) è specificata in **`ARCHITECTURE.md`**.
