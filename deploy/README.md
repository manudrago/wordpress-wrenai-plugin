# Installare Wren AI e collegarlo al plugin

> **Non è più obbligatorio.** Dalla 1.2.0 il plugin di default chiama direttamente un modello
> (Google AI Studio, Groq, OpenAI, o un endpoint OpenAI-compatibile): niente server, niente
> deploy dello schema, basta una API key nelle impostazioni. Quello che segue serve se vuoi
> comunque Wren AI — semantic layer, vector store, il suo modo di ragionare sullo schema.

`install-wren-ai.sh` gira su **qualsiasi macchina Ubuntu/Debian con root**: un
VPS, un PC di casa, un Mac con una VM Linux, la vecchia istanza Oracle. Non c'è
niente di specifico a un cloud.

Due decisioni, indipendenti tra loro:

1. **Dove pensa il modello** — in locale con Ollama (gratis, serve una macchina
   con 8 GB) oppure su un servizio hosted con free tier (Google AI Studio o
   Groq: gratis, veloce, e la macchina può avere 2 GB).
2. **Come ci arriva WordPress** — tunnel Cloudflare (nessuna porta aperta,
   indirizzo `https://`, nessun dominio necessario) oppure porta aperta solo
   all'IP del tuo server WordPress.

> **Cosa esce dalla macchina.** Il plugin esegue le query **sul database
> WordPress**, non le manda a Wren AI. A Wren AI (e quindi al modello) arrivano
> solo la domanda e lo *schema*: nomi di tabelle, colonne e le descrizioni che
> scrivi tu. I dati dei clienti, degli ordini, degli utenti restano dove sono.
> Se anche quello schema non deve uscire, usa Ollama.

---

## Il modo più corto: un comando generato da WordPress

**Wren AI → Impostazioni → "Collega un server automaticamente"**: incolli la
chiave di Google AI Studio (gratuita, la prendi qui sotto), premi *Genera il
comando* e ottieni una riga sola da incollare come root sulla macchina.

Quella riga installa tutto, apre un tunnel Cloudflare e **rimanda endpoint e
API key a WordPress da sola**: non devi copiare niente a mano, la pagina delle
impostazioni si riempie e si ricarica quando il server si fa vivo.

Il codice di pairing:

* vale **un'ora** e viaggia solo dentro il comando che hai generato;
* è salvato sul sito solo come hash, mai in chiaro;
* si brucia da solo dopo 10 tentativi sbagliati;
* resta valido finché il server continua a farsi vivo, **solo** se lasci
  spuntato *"Consenti a quel server di correggere l'endpoint"* — serve perché
  il quick tunnel cambia indirizzo a ogni riavvio, e un timer sulla macchina
  ricomunica quello nuovo entro 5 minuti. Puoi chiuderlo quando vuoi con
  *Chiudi pairing*.

Se preferisci fare a mano, o non vuoi che la macchina parli con WordPress,
tutto il resto di questa pagina funziona esattamente come prima: il pairing è
`--pair-url` + `--pair-code`, ed è opzionale.

---

## Strada consigliata: modello hosted gratuito + tunnel

Funziona su qualsiasi macchina da 2 GB, non apre porte, e risponde in pochi
secondi invece che in minuti.

1. Prendi una chiave gratuita su <https://aistudio.google.com/apikey>
   (Google AI Studio, tier gratuito: nessuna carta di credito).
2. Sulla macchina Linux:

   ```bash
   git clone https://github.com/manudrago/wordpress-wrenai-plugin.git
   cd wordpress-wrenai-plugin/deploy

   sudo bash install-wren-ai.sh \
       --llm google --llm-api-key AIza...LA_TUA_CHIAVE \
       --quick-tunnel \
       --token "$(openssl rand -hex 16)"
   ```

3. Alla fine lo script stampa **endpoint e token**. L'endpoint è del tipo
   `https://qualcosa-di-random.trycloudflare.com`.

Vuoi vedere prima cosa farebbe, senza toccare niente? `--dry-run` (funziona
anche senza sudo).

### Cosa sapere sul quick tunnel

* L'indirizzo è casuale e **cambia a ogni riavvio del container** del tunnel.
  Quando cambia, rimettilo nelle impostazioni del plugin:
  `docker logs wren-tunnel | grep trycloudflare.com`.
* Per un indirizzo stabile serve un tunnel *named*: crealo nel pannello
  Cloudflare (Zero Trust → Networks → Tunnels), punta il public hostname a
  `http://wren-gateway:8080` e installa con `--tunnel-token <token>`.
* In entrambi i casi il traffico è HTTPS fino a Cloudflare, quindi il token non
  viaggia in chiaro — cosa che invece succede con `--gateway-port 80`.

---

## Alternativa: porta aperta solo a WordPress

Se la macchina ha un IP pubblico e preferisci non passare da Cloudflare:

```bash
sudo bash install-wren-ai.sh \
    --llm google --llm-api-key AIza... \
    --gateway-port 80 --token "$(openssl rand -hex 16)" \
    --allow-ip <IP_PUBBLICO_DEL_SERVER_WORDPRESS>
```

`--gateway-port 80` perché molti hosting condivisi lasciano uscire solo 80 e
443: se il tuo WordPress può uscire su porte alte, `8080` va benissimo. Se il
provider ha un suo firewall (security list Oracle, cloud firewall Hetzner,
security group AWS) va aperta anche lì, sempre solo verso quell'IP.

---

## Tutto in locale, senza servizi esterni

```bash
sudo bash install-wren-ai.sh --llm ollama --quick-tunnel --token "$(openssl rand -hex 16)"
```

Servono ~8 GB di RAM e ~20 GB di disco: scarica `qwen2.5-coder:7b` (chat) e
`nomic-embed-text` (embedding). Senza GPU una domanda costa **30 secondi - 2
minuti**; il plugin fa polling fino a ~12 minuti, quindi non va in timeout, ma
l'esperienza è "chiedi e aspetta".

Via di mezzo: `--llm groq --llm-api-key gsk_...` usa Groq (free tier, molto
veloce) per il ragionamento e tiene in locale solo l'embedder, che è un modello
da 274 MB e sulla CPU non si sente.

---

## Dove farlo girare

| Opzione | Costo | Note |
|---|---|---|
| Una macchina che hai già accesa | 0 | Con `--quick-tunnel` non serve IP pubblico né aprire porte. Se si spegne, il plugin smette di rispondere. |
| VPS piccolo (Hetzner CX22 / CAX11, ~4 €/mese) | ~4 €/mese | 4 GB: perfetto con modello hosted. Per Ollama serve il taglio da 8 GB. |
| Oracle Cloud Always Free | 0 | Quello che avevi. Se recuperi l'accesso, `oracle-create-vm.sh` è ancora lì e funziona. |
| Wren AI Cloud | a pagamento | Nessun server da gestire: nelle impostazioni del plugin metti il loro endpoint, prefix `/api/v1` e la tua API key. |

---

## Cosa fa `install-wren-ai.sh`

| Passo | Dettaglio |
|---|---|
| Controlli | architettura (imposta `PLATFORM` di Docker), RAM, disco |
| Docker | installazione ufficiale + plugin compose |
| Ollama | solo se serve: installa, ascolta su `0.0.0.0:11434` per i container, e **blocca quella porta a tutto tranne il bridge Docker** |
| Wren AI | clona `legacy/v1` in `/opt/wrenai`, scrive `.env` (piattaforma, porta, chiavi del provider) |
| `config.yaml` | generato da `make-wren-config.py` a partire dall'esempio ufficiale della versione |
| Avvio | solo `qdrant` + `wren-ai-service`: il plugin esegue l'SQL da sé, quindi UI/engine/ibis resterebbero a consumare RAM per nulla |
| SQL validator | nginx da 10 MB che risponde al dry-run che Wren AI fa su ogni SQL prima di restituirlo (senza, ogni domanda finisce in `NO_RELEVANT_SQL`) |
| Gateway | con `--token`, un nginx davanti che pretende `Authorization: Bearer <token>` |
| Tunnel | con `--quick-tunnel` o `--tunnel-token`, un `cloudflared` che espone il gateway senza aprire porte |
| Pairing | con `--pair-url` e `--pair-code`, manda endpoint e token al plugin; con il quick tunnel installa anche un timer che ricomunica l'indirizzo quando cambia |
| Firewall | con `--allow-ip`, apre la porta **solo** a quell'indirizzo |

`make-wren-config.py` riscrive **solo** le sezioni `llm`, `embedder` e
`document_store`; `engine` e le 34 `pipes` vengono copiate dall'esempio ufficiale
della versione che hai clonato — se ne manca una, il servizio non parte.

Applica anche un tuning: disattiva intent classification, sql-generation
reasoning e functions retrieval. Sono chiamate LLM extra per ogni domanda:
pesanti su CPU, e su un free tier sono la differenza tra rispondere e sbattere
nel rate limit. Per il comportamento originale: `--full-pipeline`.

---

## Dopo l'installazione, in WordPress

**Wren AI → Impostazioni**

| Campo | Valore |
|---|---|
| Endpoint | quello stampato dallo script (`https://...trycloudflare.com`, oppure `http://IP:porta`) |
| API prefix | `/v1` |
| API key | il token che hai passato a `--token` |
| Timeout richiesta | 30 secondi |

→ **Test connessione**: deve diventare verde.

**Wren AI → Dati & schema**: scegli le tabelle, scrivi il contesto di business,
premi **Costruisci e deploya lo schema**, aspetta lo stato `finished`. Con un
embedder hosted sono secondi; con Ollama su CPU qualche minuto.

Poi metti `[wren_ai_dashboard]` in una pagina e prova.

**Cambiare server non richiede di toccare il plugin**: si aggiornano endpoint e
API key, si rifà il deploy dello schema (l'indice vettoriale vive sul server
Wren AI, quindi il nuovo non sa ancora niente del tuo database) e si riparte.

---

## Manutenzione

```bash
cd /opt/wrenai/docker
docker compose ps
systemctl status wren-pair-refresh.timer           # chi comunica l'indirizzo a WordPress
docker compose logs -f wren-ai-service
docker compose restart wren-ai-service

docker logs wren-tunnel | grep trycloudflare.com   # indirizzo attuale del tunnel
docker ps --format '{{.Names}}\t{{.Status}}'       # gateway, validator, tunnel
ollama ps                                          # solo se usi Ollama
```

Cambiare modello di **embedding** cambia la dimensione dei vettori: dopo va
sempre rifatto il deploy dello schema dal plugin.

Ri-lanciare lo script è sicuro: ogni passo controlla prima di agire. È il modo
normale per cambiare provider, token o modo di esporlo.
