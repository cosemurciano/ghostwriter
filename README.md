# Ghostwriter — Plugin WordPress

Produzione di libri completi (PDF + ePub) in WordPress con AI in background.
Contratto dati e macchine a stati: [`ghostwriter-spec/README.md`](ghostwriter-spec/README.md).
Architettura: [`ARCHITECTURE.md`](ARCHITECTURE.md).

## Requisiti

- PHP 8.1+
- WordPress 6.4+
- Composer (per installare le dipendenze)

## Setup sviluppo

```bash
composer install
vendor/bin/phpunit   # unit test
```

## Stato di avanzamento (ordine di sviluppo, ARCHITECTURE.md §14)

- [x] **1. Fondamenta** — bootstrap, CPT (`gw_project`, `gw_chapter`), capability,
      tabelle custom (`gw_usage`, `gw_log`, `gw_block_revisions`), StateMachine,
      repository, validazione schemi, Dossier con lock ottimistico,
      BlockRevisionService, SourceRegistry
- [x] **2. Rendering prima dell'AI** — BlockRenderer (profili pdf/epub, note
      endnote, fallback ePub), RichText (markdown ristretto, input escapato),
      Theme + ThemeRegistry (tema di serie "Classico", import zip validato:
      schema + niente PHP + difesa zip-slip), MpdfCssCompiler ed
      EpubCssCompiler (sottoinsieme sicuro, epub_fallback, epub_value),
      PdfExporter (mPDF: mirrorMargins, TOC nativo, testatine per capitolo,
      apertura recto, tempDir esplicita, fontdata dal tema), EpubExporter
      (EPUB3 interno, spine per capitolo, nav annidata), BookAssembler
      (unico punto WP del rendering)
- [x] **3. Coda e pipeline** — Dispatcher su Action Scheduler (dedup
      {job}:{project}:{chapter}, 3 tentativi con backoff 60/240s, on_failure →
      stato failed), ProviderInterface + MockProvider (output sempre conformi
      agli schemi), job: ProposeOutline, MaterializeChapters, DraftChapter
      (retry mirato su errore di validazione), Synopsis, ReviewChapter,
      RewriteBlock (versioning con feedback), Export; PipelineRouter su
      gw_state_changed (generazione sequenziale, immagini saltate fino alla
      fase 5)
- [x] **4. Agent layer** — AnthropicProvider (tool-use forzato, prompt
      caching sui blocchi system) e OpenAiProvider (structured outputs) su
      wp_remote_post; ProviderRouter per progetto (anthropic|openai|mock);
      ContextComposer (skills → dossier → brief → capitolo precedente → RAG,
      fonti delimitate come non fidate); SkillsManager (bundle locali,
      versioni bloccate); PhaseSchemas; UsageMeter con stima costi e budget
      cap → paused_budget, accodamento sospeso. RAG: NullRagService (vector
      store reale in fase 5 con IngestSourcesJob)
- [x] **5. Pipeline complete** — fase immagini (GenerateImageJob parallelo:
      image_brief → provider → Media Library → attachment_id nel blocco,
      capitolo complete alla risoluzione dell'ultima figura; risoluzione da
      formato progetto, 300dpi se print_ready); ingestione fonti
      (IngestSourcesJob: TextExtractor per PDF/testo/URL → chunking → vector
      store locale gw_rag_chunks); IndexChapterJob sui capitoli completati;
      REST API ghostwriter/v1 (progetti, fonti, outline propose/edit/approve,
      budget resume, capitoli, riscrittura/versioni/ripristino blocchi,
      export con download autenticato, temi, skills)
- [ ] 6. Copertina e preflight/export end-to-end
- [ ] 7. Traduzioni (progetti derivati + glossario)
- [ ] 8. Admin UI rifinita

## Deviazioni dalla spec

- **Validazione schemi**: si usa `opis/json-schema` al posto di
  `justinrainbow/json-schema` (ARCHITECTURE.md §1) perché justinrainbow non
  supporta le condizionali `if/then` di draft-07, su cui gli schemi basano la
  validazione per-tipo delle props dei blocchi: con justinrainbow i blocchi
  malformati passerebbero silenziosamente (verificato con test).
- **Chiavi API in wp-config.php**: al posto delle opzioni cifrate con sodium
  (ARCHITECTURE.md §3), le chiavi si definiscono come costanti PHP e non
  toccano mai il database né i log:

  ```php
  define( 'GHOSTWRITER_ANTHROPIC_API_KEY', 'sk-ant-...' );
  define( 'GHOSTWRITER_OPENAI_API_KEY',    'sk-...' );
  ```

  (accettate anche le convenzionali `ANTHROPIC_API_KEY` / `OPENAI_API_KEY`).
- **Provider `mock` ammesso dallo schema**: l'enum `ai.provider` della copia
  plugin di project-config.schema.json include `mock` per sviluppo/test della
  pipeline senza rete.
- **RAG locale lessicale (v1)**: il "vector store per progetto" è
  implementato come indice locale (tabella `gw_rag_chunks`, chunking +
  scoring TF-IDF in memoria): nessuna chiamata esterna, nessun lock-in.
  Il passaggio a embeddings/vector store del provider potrà sostituire
  `LocalRagService` dietro la stessa interfaccia.
- **Provenienza della versione corrente di un blocco**: tracciata nel blocco
  stesso (chiavi interne `origin`, `generated_with`, `author_user_id`,
  `restored_from_version` nel formato intermedio, ammesse dallo schema) così
  l'archiviazione in `gw_block_revisions` conosce l'origine dello snapshot che
  archivia. Le chiavi diventano colonne della revisione al momento dell'archivio.
