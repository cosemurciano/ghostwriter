# Ghostwriter — Plugin WordPress

Produzione di libri completi (PDF + ePub) in WordPress con AI in background.
Contratto dati e macchine a stati: [`../ghostwriter-spec/README.md`](../ghostwriter-spec/README.md).
Architettura: [`../ARCHITECTURE.md`](../ARCHITECTURE.md).

## Requisiti

- PHP 8.1+
- WordPress 6.4+
- Composer (per installare le dipendenze)

## Setup sviluppo

```bash
cd ghostwriter-plugin
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
- [ ] 3. Coda e pipeline (Action Scheduler, Dispatcher, job con provider mock)
- [ ] 4. Agent layer (provider reali, ContextComposer, SkillsManager, RagService, UsageMeter)
- [ ] 5. Pipeline complete (outline → capitoli → immagini → dossier)
- [ ] 6. Copertina e preflight/export end-to-end
- [ ] 7. Traduzioni (progetti derivati + glossario)
- [ ] 8. Admin UI rifinita

## Deviazioni dalla spec

- **Validazione schemi**: si usa `opis/json-schema` al posto di
  `justinrainbow/json-schema` (ARCHITECTURE.md §1) perché justinrainbow non
  supporta le condizionali `if/then` di draft-07, su cui gli schemi basano la
  validazione per-tipo delle props dei blocchi: con justinrainbow i blocchi
  malformati passerebbero silenziosamente (verificato con test).
- **Provenienza della versione corrente di un blocco**: tracciata nel blocco
  stesso (chiavi interne `origin`, `generated_with`, `author_user_id`,
  `restored_from_version` nel formato intermedio, ammesse dallo schema) così
  l'archiviazione in `gw_block_revisions` conosce l'origine dello snapshot che
  archivia. Le chiavi diventano colonne della revisione al momento dell'archivio.
