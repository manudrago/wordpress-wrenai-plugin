# Wren AI su Oracle Cloud (Always Free) con Ollama

Tutto gratis: VM ARM sempre accesa nel free tier di Oracle, modello locale via
Ollama, nessuna chiave OpenAI. Due strade — la prima fa quasi tutto da sola.

Le immagini Docker di Wren AI sono pubblicate anche per `linux/arm64`, quindi
girano sulle Ampere A1 di Oracle senza emulazione (verificato sul registry).

---

## Strada A — un comando nella Cloud Shell (consigliata)

La Cloud Shell è il terminale dentro la console Oracle: la sua CLI è già
autenticata come te, quindi non devi configurare chiavi API.

1. Apri la console: <https://cloud.oracle.com/?region=uk-london-1>
2. In alto a destra clicca l'icona **`>_`** (Cloud Shell) e aspetta il prompt.
3. Porta lì i due file di questa cartella. O con git, se il repo è pubblico:

   ```bash
   git clone https://github.com/manudrago/wordpress-wrenai-plugin.git
   cd wordpress-wrenai-plugin/deploy
   ```

   oppure con il menu **⋮ → Upload** della Cloud Shell, caricando
   `oracle-create-vm.sh`, `oracle-arm-install.sh` e `make-ollama-config.py`.

4. Lancia (metti l'IP pubblico del server dove gira WordPress):

   ```bash
   bash oracle-create-vm.sh --wp-ip 203.0.113.10
   ```

Lo script crea rete, regole, chiave SSH e istanza **4 OCPU / 24 GB / 100 GB**
(l'intera quota Always Free ARM), e le passa un cloud-init che installa Docker,
Ollama, i modelli e Wren AI da solo. Alla fine stampa **IP pubblico e token**.

L'installazione a bordo richiede 15-30 minuti, quasi tutti spesi a scaricare i
pesi del modello. Puoi seguirla:

```bash
ssh -i ~/.ssh/wren_ai_ed25519 ubuntu@IP 'tail -f /var/log/wren-install.log'
```

È pronta quando questo risponde `{"status":"ok"}`:

```bash
ssh -i ~/.ssh/wren_ai_ed25519 ubuntu@IP 'curl -s localhost:5555/health'
```

### Se dice "Out of host capacity"

Non è un errore tuo: le ARM gratuite sono spesso esaurite. Lo script prova tutti
gli availability domain della region e si ferma senza lasciare nulla a metà —
rilancialo più tardi o prova un'altra region. Niente viene ricreato due volte.

---

## Strada B — VM creata a mano, installazione via script

Se preferisci cliccare nella console:

1. **Compute → Instances → Create instance**
2. *Image and shape* → **Change shape** → **Ampere** → `VM.Standard.A1.Flex`,
   **4 OCPU** e **24 GB** (dentro la quota gratuita).
3. *Image* → **Canonical Ubuntu 24.04** (build `aarch64`).
4. *Networking* → assegna un **IP pubblico**.
5. *Add SSH keys* → carica la tua chiave pubblica.
6. *Boot volume* → 100 GB.
7. Crea, poi collegati e installa:

   ```bash
   ssh ubuntu@IP
   sudo apt-get update && sudo apt-get install -y git
   git clone https://github.com/manudrago/wordpress-wrenai-plugin.git
   cd wordpress-wrenai-plugin/deploy
   sudo bash oracle-arm-install.sh --allow-ip <IP_DEL_SERVER_WORDPRESS> --token <SCEGLI_UN_SEGRETO>
   ```

8. Nella console, **Networking → VCN → Security List** della subnet: aggiungi
   una ingress rule TCP sulla porta **8080** con *source* l'IP di WordPress.

---

## Cosa fa `oracle-arm-install.sh`

| Passo | Dettaglio |
|---|---|
| Controlli | architettura (imposta `PLATFORM` di Docker), RAM, disco |
| Docker | installazione ufficiale + plugin compose |
| Ollama | installa, lo fa ascoltare su `0.0.0.0:11434` per i container, e **blocca quella porta a tutto tranne il bridge Docker** |
| Modelli | `qwen2.5-coder:7b` (chat) e `nomic-embed-text` (embedding, 768 dim) |
| Wren AI | clona `legacy/v1` in `/opt/wrenai`, imposta `PLATFORM=linux/arm64` nel `.env` |
| `config.yaml` | generato da `make-ollama-config.py` a partire dall'esempio ufficiale della versione |
| Avvio | solo `qdrant` + `wren-ai-service`: il plugin esegue l'SQL da sé, quindi UI/engine/ibis resterebbero a consumare RAM per nulla |
| Gateway | con `--token`, un nginx davanti che pretende `Authorization: Bearer <token>` |
| Firewall | apre la porta **solo** all'IP passato con `--allow-ip` |

`make-ollama-config.py` riscrive **solo** le sezioni `llm`, `embedder` e
`document_store`; `engine` e le 34 `pipes` vengono copiate dall'esempio ufficiale
della versione che hai clonato — se ne manca una, il servizio non parte.

Applica anche un tuning pensato per la CPU: disattiva intent classification,
sql-generation reasoning e functions retrieval. Sono chiamate LLM extra per ogni
domanda: trascurabili su un modello hosted, pesanti su quattro core ARM. Per
tenere il comportamento originale: `--no-cpu-tuning`.

---

## Dopo l'installazione, in WordPress

**Wren AI → Impostazioni**

| Campo | Valore |
|---|---|
| Endpoint | `http://IP_DELLA_VM:8080` (`:5555` se non hai usato `--token`) |
| API prefix | `/v1` |
| API key | il token stampato dallo script |
| Timeout richiesta | 30 secondi |

→ **Test connessione**: deve diventare verde.

**Wren AI → Dati & schema**: scegli le tabelle, scrivi il contesto di business,
premi **Costruisci e deploya lo schema**. Il primo deploy fa passare ogni colonna
dall'embedder: su CPU mettici qualche minuto. Aspetta lo stato `finished`.

Poi metti `[wren_ai_dashboard]` in una pagina e prova.

---

## Aspettative oneste sulle prestazioni

Senza GPU, un 7B quantizzato fa circa 5-15 token/s. Ogni domanda sono due
chiamate al modello (SQL e grafico), quindi **30 secondi - 2 minuti a domanda**,
contro i ~5-10 secondi di un modello hosted. Il plugin fa polling e regge fino a
~12 minuti per domanda (`wwd_max_poll_steps`), quindi non va in timeout, ma
l'esperienza è "chiedi e aspetta", non istantanea.

Con 24 GB puoi passare a `phi4:14b` (`--model phi4:14b`): SQL più affidabile,
circa il doppio del tempo. Se un giorno vuoi velocità, cambiare `config.yaml` per
puntare a un provider hosted è questione di due righe.

## Manutenzione

```bash
cd /opt/wrenai/docker
docker compose ps
docker compose logs -f wren-ai-service
docker compose restart wren-ai-service
ollama ps                    # modello caricato e RAM occupata
free -h
```

Dopo un cambio di modello (`ollama pull ...` + modifica di `config.yaml`) va
rifatto il deploy dello schema dal plugin: cambiando embedder cambia la
dimensione dei vettori e l'indice va ricostruito.
