# WP Wren Dashboards

Plugin WordPress che legge il database del sito e, in una pagina qualsiasi (via shortcode),
mostra un form dove chiunque sia autorizzato può **chiedere qualsiasi cosa sui propri dati in
linguaggio naturale** e ottenere subito una risposta con **grafico + tabella**, salvabile come
pannello di una **dashboard**.

Il cervello è [Wren AI](https://github.com/Canner/WrenAI): il plugin gli manda lo schema del
database (solo la struttura, mai i contenuti), gli passa la domanda, riceve l'SQL e lo
spec del grafico, esegue la query in sola lettura su WordPress e disegna il risultato.

```
Domanda ──▶ WP REST ──▶ Wren AI /v1/asks ──▶ SQL
                             │
                    guard SQL (solo SELECT, tabelle consentite, LIMIT)
                             │
                        $wpdb ──▶ righe ──▶ Wren AI /v1/charts ──▶ Vega-Lite
                                                    │
                                         renderer SVG incluso ──▶ grafico
```

---

## Indice

- [Cosa ottieni](#cosa-ottieni)
- [Requisiti](#requisiti)
- [Installazione](#installazione)
- [Configurazione in 4 passi](#configurazione-in-4-passi)
- [Shortcode](#shortcode)
- [Sicurezza](#sicurezza)
- [Come funziona dentro](#come-funziona-dentro)
- [Hook per sviluppatori](#hook-per-sviluppatori)
- [Test](#test)
- [Limiti noti](#limiti-noti)

---

## Cosa ottieni

- **`[wren_ai_dashboard]`** — il form "chiedi qualsiasi cosa". Domanda → SQL → dati → grafico.
  Include domande di esempio cliccabili, follow-up conversazionali ("e per l'anno scorso?"),
  export CSV, SQL a vista (disattivabile) e pulsante *Salva nella dashboard*.
- **`[wren_dashboard id="12"]`** — una dashboard salvata: griglia di pannelli, ognuno
  ri-eseguito dal vivo a ogni caricamento (con cache), con refresh automatico opzionale.
- **Admin** — connessione a Wren AI, scelta delle tabelle condivise, contesto di business,
  deploy del modello semantico, log delle query, gestione dashboard e pannelli.
- **Grafici senza dipendenze esterne**: nessuna CDN, nessun Vega runtime da 800 KB. Il plugin
  interpreta il sottoinsieme di Vega-Lite che Wren AI produce (bar, grouped/stacked bar, line,
  multi-line, area, pie, KPI) e lo disegna in SVG inline (~20 KB di JS, dark mode inclusa).

## Requisiti

- WordPress 6.0+, PHP 7.4+
- MySQL 5.7+ / MariaDB 10.3+
- Un'istanza di **Wren AI** raggiungibile via HTTP dal server WordPress

> **Nota sulle versioni di Wren AI.** Il plugin parla la REST API di `wren-ai-service`
> (`/v1/asks`, `/v1/charts`, `/v1/semantics-preparations`). Quell'API è quella di
> **Wren AI self-hosted "GenBI Classic"** (branch [`legacy/v1`](https://github.com/Canner/WrenAI/tree/legacy/v1),
> tag `v1-final`, immagini Docker `ghcr.io/canner/wren-ai-service`) e di **Wren AI Cloud**.
> Il `main` attuale di WrenAI è stato riorganizzato come CLI/SDK agent-driven (`pip install wrenai`)
> e non espone quel servizio HTTP. Vedi [`docs/wren-ai-setup.md`](docs/wren-ai-setup.md) per
> entrambe le strade.

## Installazione

Questo repository **è** il plugin: la sua radice va copiata in una cartella chiamata
`wp-wren-dashboards` dentro `wp-content/plugins/`.

```bash
git clone https://github.com/manudrago/wordpress-wrenai-plugin.git \
  /path/to/wp-content/plugins/wp-wren-dashboards
# poi attiva "WP Wren Dashboards" da wp-admin → Plugin
```

Oppure genera lo zip da caricare da wp-admin (crea la cartella con il nome giusto):

```bash
./bin/build-zip.sh
# → dist/wp-wren-dashboards.zip
```

All'attivazione il plugin crea la tabella di log `{prefix}wwd_query_log` e il tipo di
contenuto `wwd_dashboard`.

## Configurazione in 4 passi

### 1. Avvia Wren AI

Il modo più rapido (Docker, GenBI Classic):

```bash
git clone -b legacy/v1 https://github.com/Canner/WrenAI.git wrenai
cd wrenai/docker
cp .env.example .env          # metti la tua OPENAI_API_KEY
cp config.example.yaml config.yaml
docker compose up -d
# wren-ai-service risponde su http://localhost:5555
```

Dettagli, alternative (Ollama, modelli locali) e Wren AI Cloud: [`docs/wren-ai-setup.md`](docs/wren-ai-setup.md).

**Non hai un server?** [`deploy/`](deploy/README.md) contiene l'installazione
automatica su una VM ARM gratuita di Oracle Cloud con Ollama: un comando nella
Cloud Shell crea la macchina e installa tutto (zero costi, nessuna chiave OpenAI).

### 2. Collega il plugin

**wp-admin → Wren AI → Impostazioni**

| Campo | Valore tipico |
|---|---|
| Endpoint | `http://localhost:5555` (o l'host raggiungibile dal server WP) |
| API prefix | `/v1` — usa `/api/v1` per Wren AI Cloud |
| API key | vuoto in locale, il token Bearer su Cloud |
| Lingua risposte | vuoto = lingua del sito |

Premi **Test connessione**: deve diventare verde.

### 3. Scegli i dati e fai il deploy dello schema

**wp-admin → Wren AI → Dati & schema**

- Seleziona le tabelle che Wren AI può vedere (di default: `posts`, `postmeta`, `terms`,
  `term_taxonomy`, `term_relationships`, `comments`).
- Le colonne in *"Non esporre mai queste colonne"* (`user_pass`, `user_activation_key`,
  `user_email`, …) vengono rimosse dal modello, rifiutate nell'SQL generato e mascherate nei
  risultati.
- Scrivi il **contesto di business**: è la leva più forte sulla qualità delle risposte.
  Esempio: *"I prodotti sono post_type = 'product'; il prezzo è in postmeta con meta_key
  '_price'; un cliente attivo ha almeno un ordine negli ultimi 90 giorni."*
- Premi **Costruisci e deploya lo schema**. Il plugin genera l'MDL (modello semantico) dal
  database — solo struttura, mai contenuti — e lo indicizza su Wren AI.

Rifai il deploy ogni volta che cambi le tabelle condivise, il contesto o lo schema del sito.

### 4. Pubblica la pagina

Crea una pagina (es. `/analytics`) e inserisci:

```
[wren_ai_dashboard]
```

Fatto: chi ha il permesso può fare domande e salvare le risposte come pannelli.

## Shortcode

### `[wren_ai_dashboard]`

| Attributo | Default | Descrizione |
|---|---|---|
| `dashboard` | — | ID della dashboard preselezionata nel salvataggio pannelli |
| `title` | — | Titolo sopra il form |
| `placeholder` | *"Ask anything about your data…"* | Testo del campo |
| `examples` | 4 esempi | Domande suggerite, separate da `\|` |
| `height` | `340` | Altezza dei grafici in px |

```
[wren_ai_dashboard dashboard="12" title="Chiedi ai dati"
  examples="Vendite di questo mese|Top 10 autori|Commenti in moderazione"]
```

Alias: `[wren_ask]`.

### `[wren_dashboard]`

| Attributo | Default | Descrizione |
|---|---|---|
| `id` | — | **obbligatorio**, ID della dashboard |
| `title` | titolo del post | Intestazione |
| `refresh` | `0` | Secondi tra un aggiornamento automatico e l'altro (0 = mai) |

```
[wren_dashboard id="12" refresh="300"]
```

## Sicurezza

Il modello linguistico è trattato come una **fonte non fidata di SQL**. Fra Wren AI e il
database ci sono cinque livelli:

1. **Permessi.** Chiedere richiede una capability configurabile (default `edit_posts`) e
   l'accesso pubblico è opt-in esplicito. Salvare pannelli richiede una seconda capability.
   Tutte le rotte REST passano da nonce + `permission_callback`.
2. **Allow-list di tabelle.** Ogni tabella citata dall'SQL (FROM/JOIN, CTE escluse) deve essere
   fra quelle condivise; altrimenti la query è rifiutata prima di toccare il database.
3. **Guard SQL** (`includes/class-wwd-sql-guard.php`): solo `SELECT`/`WITH`, una sola
   istruzione, niente commenti-trucco, niente `INSERT/UPDATE/DELETE/DROP/ALTER/GRANT/SET/INTO
   OUTFILE/LOAD_FILE/SLEEP/BENCHMARK/@@variabili`, niente `information_schema`, `mysql.`,
   `performance_schema`, `sys.`; `LIMIT` forzato al massimo configurato.
4. **Colonne vietate.** Rimosse dal modello, rifiutate nell'SQL, mascherate (`***`) nei risultati.
5. **Connessione dedicata (consigliata).** Con queste costanti in `wp-config.php` tutte le
   query analitiche passano da un utente MySQL con solo `SELECT`:

   ```php
   define( 'WWD_DB_USER', 'wp_readonly' );
   define( 'WWD_DB_PASSWORD', '…' );
   ```

   ```sql
   CREATE USER 'wp_readonly'@'%' IDENTIFIED BY '…';
   GRANT SELECT ON wordpress.wp_posts    TO 'wp_readonly'@'%';
   GRANT SELECT ON wordpress.wp_postmeta TO 'wp_readonly'@'%';
   -- …una riga per ogni tabella condivisa
   ```

In più: rate limit per utente (o per IP se anonimo), cache dei risultati, log completo di ogni
domanda e istruzione eseguita (**Wren AI → Query log**), e i pannelli salvati contengono solo
SQL già approvato dal guard — il browser non può iniettare SQL proprio, perché il salvataggio
usa la sessione lato server, non il testo inviato dal client.

**Cosa esce dal sito:** la domanda, i nomi di tabelle/colonne (l'MDL) e un campione di
massimo 200 righe di risultato, inviato a Wren AI per disegnare il grafico. Se anche quello è
troppo, riduci `wwd_chart_sample_rows` a 0 via filtro: il grafico verrà scelto sui soli nomi
di colonna.

## Come funziona dentro

| File | Ruolo |
|---|---|
| `includes/class-wwd-settings.php` | Opzioni, default, sanitizzazione |
| `includes/class-wwd-schema.php` | Introspezione MySQL → MDL (modelli, colonne, relazioni, descrizioni delle tabelle WordPress) |
| `includes/class-wwd-wren-client.php` | Client HTTP: `semantics-preparations`, `asks`, `charts`, `health` |
| `includes/class-wwd-sql-guard.php` | Normalizzazione (identificatori Wren → MySQL, `DATE_TRUNC` → `DATE_FORMAT`, cast) e validazione |
| `includes/class-wwd-query-runner.php` | Esecuzione, mascheramento, cache, connessione read-only |
| `includes/class-wwd-ask-session.php` | Macchina a stati della domanda: `generating_sql → running_query → generating_chart → done` |
| `includes/class-wwd-rest.php` | Rotte `/wp-json/wren-ai/v1/*` |
| `includes/class-wwd-dashboards.php` | CPT `wwd_dashboard` e pannelli |
| `assets/js/wwd-chart.js` | Renderer Vega-Lite → SVG |

Wren AI risponde in modo asincrono: il browser fa polling su `GET /ask/{id}` e ogni chiamata
avanza la macchina a stati di un passo, così nessuna richiesta PHP resta appesa un minuto.

### Rotte REST

| Metodo | Rotta | Permesso |
|---|---|---|
| `POST` | `/wren-ai/v1/ask` | capability "chiedi" |
| `GET` | `/wren-ai/v1/ask/{id}` | capability "chiedi" |
| `POST` | `/wren-ai/v1/ask/{id}/stop` | capability "chiedi" |
| `GET` | `/wren-ai/v1/dashboards` | capability "salva" |
| `POST` | `/wren-ai/v1/dashboards/{id}/panels` | capability "salva" |
| `DELETE` | `/wren-ai/v1/dashboards/{id}/panels/{panel}` | capability "salva" |
| `GET` | `/wren-ai/v1/dashboards/{id}/panels/{panel}/data` | capability "chiedi" |
| `POST` | `/wren-ai/v1/schema/sync` | `manage_options` |
| `GET` | `/wren-ai/v1/schema/status`, `/health` | `manage_options` |

## Hook per sviluppatori

```php
// Aggiungi tabelle/relazioni custom al modello semantico.
add_filter( 'wwd_mdl', function ( $mdl ) { /* … */ return $mdl; } );
add_filter( 'wwd_mdl_relationships', function ( $rels, $tables ) { /* … */ return $rels; }, 10, 2 );

// Domande di esempio sotto il form.
add_filter( 'wwd_example_questions', function () {
	return array( 'Fatturato per mese', 'Prodotti senza vendite' );
} );

// Tempi e limiti.
add_filter( 'wwd_query_timeout_ms', fn() => 8000 );
add_filter( 'wwd_chart_sample_rows', fn() => 50 );
add_filter( 'wwd_poll_interval_ms', fn() => 800 );
add_filter( 'wwd_thread_length', fn() => 3 );

// Header/proxy per le chiamate a Wren AI.
add_filter( 'wwd_request_args', function ( $args, $url, $method ) { /* … */ return $args; }, 10, 3 );
```

## Test

Tre suite, nessuna dipendenza: servono solo `php` e `node`.

```bash
./tests/run.sh
# 27 checks, 0 failures   (guard SQL)
# 31 checks, 0 failures   (MDL + payload Wren AI)
# 19 checks, 0 failures   (renderer grafici)
```

- **`tests/test-sql-guard.php`** — la parte che conta davvero: rewrite degli identificatori
  Wren, CTE, letterali che sembrano keyword, traduzione `DATE_TRUNC`, clamp del `LIMIT`, e i
  rifiuti (write, statement multipli, UNION verso tabelle non condivise, `information_schema`,
  `SLEEP`, `INTO OUTFILE`, `LOAD_FILE`, `@@variabili`, colonne vietate).
- **`tests/test-wren-payloads.php`** — l'MDL generato da un database in stile WordPress
  (tipi, chiavi, join impliciti, colonne vietate rimosse) e la forma esatta delle richieste a
  `/v1/semantics-preparations`, `/v1/asks`, `/v1/charts`, confrontata con i modelli pydantic
  di `wren-ai-service`.
- **`tests/test-chart-renderer.js`** — il renderer Vega-Lite su un DOM finto: bar, line,
  grouped/stacked bar, pie, multi-line con `fold`, KPI, e i fallback a tabella.

## Limiti noti

- **Dialetto SQL.** Wren AI pianifica sul proprio motore; il plugin traduce i costrutti più
  comuni verso MySQL (`DATE_TRUNC`, cast, `ILIKE`, identificatori quotati). Se il modello
  produce qualcosa di esotico, la query viene rifiutata dal database con un messaggio chiaro:
  aggiungere la regola in `WWD_SQL_Guard::translate_functions()` o rafforzare il contesto di
  business risolve quasi sempre.
- **Un modello per sito.** Multisite: un deploy per sito, usando `project_id` diversi.
- **Grafici.** Il renderer copre i tipi che Wren AI genera; spec Vega-Lite molto elaborate
  ricadono sulla tabella (che è comunque sempre disponibile e scaricabile in CSV).
- **Accesso pubblico.** Attivabile, ma vuol dire davvero permettere a chiunque query aggregate
  sulle tabelle condivise: fallo solo con tabelle innocue.

## Licenza

GPL-2.0-or-later, come WordPress.
