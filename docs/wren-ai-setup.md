# Preparare Wren AI per il plugin

Il plugin ha bisogno di un endpoint HTTP che parli la REST API di `wren-ai-service`:

| Metodo | Rotta | A cosa serve |
|---|---|---|
| `POST` | `/v1/semantics-preparations` | indicizza il modello semantico (MDL) generato dal plugin |
| `GET` | `/v1/semantics-preparations/{mdl_hash}/status` | stato dell'indicizzazione |
| `POST` | `/v1/asks` | domanda in linguaggio naturale → job di text-to-SQL |
| `GET` | `/v1/asks/{query_id}/result` | risultato: SQL + ragionamento |
| `PATCH` | `/v1/asks/{query_id}` | interrompi una domanda |
| `POST` | `/v1/charts` | dati + domanda → schema Vega-Lite |
| `GET` | `/v1/charts/{query_id}` | risultato del grafico |
| `GET` | `/health` | healthcheck |

Ci sono due modi per avere quell'endpoint.

---

## Opzione A — Self-hosted con Docker (Wren GenBI Classic)

Il `main` di [Canner/WrenAI](https://github.com/Canner/WrenAI) oggi è una CLI/SDK guidata da
agenti (`pip install wrenai`) e **non** espone il servizio HTTP sopra. Il servizio vive sul
branch `legacy/v1` (tag `v1-final`), pubblicato come immagine Docker mantenuta:

```bash
git clone -b legacy/v1 https://github.com/Canner/WrenAI.git wrenai
cd wrenai/docker

cp .env.example .env
cp config.example.yaml config.yaml
```

Nel file `.env` bastano due cose:

```dotenv
OPENAI_API_KEY=sk-…            # oppure configura un altro provider in config.yaml
AI_SERVICE_FORWARD_PORT=5555   # porta esposta sull'host
```

Avvia:

```bash
docker compose up -d
curl http://localhost:5555/health     # {"status":"ok"}
```

Nel plugin (**Wren AI → Impostazioni**):

- Endpoint: `http://localhost:5555`
- API prefix: `/v1`
- API key: vuota

### Se WordPress gira in un altro container

Metti i due servizi sulla stessa rete Docker e usa il nome del servizio come host, per esempio
`http://wren-ai-service:5555`. Da un WordPress su Docker Desktop verso un Wren sull'host:
`http://host.docker.internal:5555`.

### Modelli locali (senza OpenAI)

`config.yaml` accetta qualsiasi provider compatibile OpenAI, Ollama incluso. Esempio minimo:

```yaml
type: llm
provider: litellm_llm
models:
  - model: openai/gpt-4o-mini
    api_base: http://ollama:11434/v1
    api_key_name: LLM_OPENAI_API_KEY
```

La configurazione completa (embedder, Qdrant, pipeline) è documentata nel repo:
`wren-ai-service/docs/configuration.md` sul branch `legacy/v1`.

---

## Opzione B — Wren AI Cloud

Se non vuoi gestire l'infrastruttura, Wren AI Cloud espone la stessa famiglia di endpoint sotto
`https://cloud.getwren.ai/api/v1` con autenticazione a token.

Nel plugin:

- Endpoint: `https://cloud.getwren.ai`
- API prefix: `/api/v1`
- API key: il token del tuo workspace (viene inviato come `Authorization: Bearer …`)

Se il tuo piano espone rotte con nomi diversi, l'unico punto da adattare è
`includes/class-wwd-wren-client.php`: i metodi sono uno per endpoint.

---

## Cosa manda il plugin a Wren AI

1. **Al deploy dello schema**: l'MDL, cioè nomi di tabelle e colonne, tipi, chiavi, relazioni e
   le descrizioni testuali (comprese le tue istruzioni di business). **Nessun contenuto delle
   righe.**
2. **A ogni domanda**: il testo della domanda, l'hash del modello e le ultime domande/SQL del
   thread per i follow-up.
3. **Per il grafico**: la domanda, l'SQL eseguito e un campione dei risultati (massimo 200
   righe, filtrabile con `wwd_chart_sample_rows`).

Se il campione di dati non deve uscire dal sito, imposta:

```php
add_filter( 'wwd_chart_sample_rows', '__return_zero' );
```

Wren AI sceglierà il tipo di grafico dalla sola struttura della query.

---

## Diagnostica

| Sintomo | Dove guardare |
|---|---|
| "Could not reach Wren AI" | il server WordPress raggiunge l'endpoint? `curl` dalla stessa macchina; attenzione a firewall e `WP_HTTP_BLOCK_EXTERNAL` |
| Deploy schema fermo su *indexing* | log di `wren-ai-service` e di `qdrant`: quasi sempre è la chiave del provider LLM o l'embedder |
| "Wren AI did not return a query" | la domanda non è mappabile sulle tabelle condivise: aggiungi contesto in **Dati & schema** |
| "The database rejected the query" | dialetto SQL: guarda l'SQL nel **Query log** e aggiungi la traduzione in `WWD_SQL_Guard::translate_functions()` |
| "touches tables that are not shared" | il modello ha inventato una tabella oppure ne serve una che non hai condiviso |
