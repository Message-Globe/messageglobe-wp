<div align="center">

# MessageGlobe per WordPress

**Il plugin ufficiale WordPress e WooCommerce per [MessageGlobe](https://messageglobe.com)** —
invia SMS, sincronizza i tuoi utenti nelle liste MessageGlobe e recapita ogni email di WordPress via SMTP.

[English](README.md) · **Italiano**

[![Ultima release](https://img.shields.io/github/v/release/Message-Globe/messageglobe-wp?sort=semver)](https://github.com/Message-Globe/messageglobe-wp/releases/latest)
[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759b?logo=wordpress&logoColor=white)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-opzionale-96588a?logo=woocommerce&logoColor=white)](https://woocommerce.com)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php&logoColor=white)](https://www.php.net)
[![Licenza: GPL-2.0-or-later](https://img.shields.io/badge/Licenza-GPLv2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

</div>

---

MessageGlobe per WordPress collega il tuo sito alla piattaforma di messaggistica
[MessageGlobe](https://messageglobe.com) e svolge tre compiti, ciascuno opzionale:

- **Email affidabili** — instrada ogni `wp_mail()` (reset password, ricevute WooCommerce, notifiche
  dei form) attraverso MessageGlobe SMTP per una migliore deliverability.
- **Notifiche SMS** — avvisa clienti e staff sugli eventi che contano, con integrazione WooCommerce
  integrata.
- **Sincronizzazione contatti** — aggiunge i tuoi utenti (email + telefono) a una lista MessageGlobe,
  filtrando per ruolo.

Un solo API token dalla [dashboard sviluppatori](https://dashboard.messageglobe.com/developers)
abilita tutto. Il plugin è costruito sull'
[SDK PHP ufficiale di MessageGlobe](https://github.com/Message-Globe/messageglobe-php), dialoga con
l'API tramite il layer HTTP di WordPress (nessun cURL grezzo) e mette in coda i messaggi in uscita,
così checkout e registrazione non aspettano mai la rete.

## Indice

- [Funzionalità](#funzionalità)
- [Requisiti](#requisiti)
- [Installazione](#installazione)
- [Configurazione](#configurazione)
- [Deliverability email (SMTP)](#deliverability-email-smtp)
- [Notifiche SMS](#notifiche-sms)
- [WooCommerce](#woocommerce)
- [Sincronizzazione utenti → lista](#sincronizzazione-utenti--lista)
- [Impostazioni e log](#impostazioni-e-log)
- [Sicurezza e privacy](#sicurezza-e-privacy)
- [Come funziona](#come-funziona)
- [Sviluppo](#sviluppo)
- [FAQ](#faq)
- [Licenza](#licenza)

## Funzionalità

| Funzionalità | Cosa fa | Richiede |
|---|---|---|
| **Email SMTP** | Instrada ogni `wp_mail()` attraverso MessageGlobe SMTP (preset a un clic o il tuo server). | — |
| **Notifiche SMS** | Avvisa clienti e staff sugli eventi del sito, con sender ID personalizzato e gateway HQ/LQ. | — |
| **SMS ordini WooCommerce** | Avvisa i clienti al cambio di stato dell'ordine (template per stato) e lo staff sui nuovi ordini. | WooCommerce |
| **Sincronizzazione utenti → lista** | Aggiunge gli utenti (email + telefono) a una lista MessageGlobe, filtrando per ruoli — agli eventi utente o **in blocco** per gli utenti esistenti, con barra di avanzamento. | — |
| **Coda asincrona** | Gli invii sono messi in coda via WP-Cron con retry: checkout e registrazione non aspettano l'API. | — |
| **Impostazioni e log** | Interfaccia a schede con selettori live, pulsanti di test (SMS, email, API e **connessione SMTP**), registro attività e vista **esecuzioni in background**. | — |

## Requisiti

- WordPress **5.8+**
- PHP **7.4+**
- Un [account MessageGlobe](https://dashboard.messageglobe.com) e un API token
- **WooCommerce** è opzionale — le sue funzioni si attivano in automatico quando è presente

## Installazione

### Da una release (consigliato)

1. Scarica **`messageglobe-x.y.z.zip`** dall'
   [ultima release](https://github.com/Message-Globe/messageglobe-wp/releases/latest) — **non**
   l'archivio "Source code" (esclude le dipendenze incluse e non funziona).
2. In bacheca vai su **Plugin → Aggiungi nuovo → Carica plugin**, scegli lo zip, clicca
   **Installa ora**, poi **Attiva**.

Lo zip della release include tutte le dipendenze (`vendor/`): niente Composer sul server.

### Da sorgente (sviluppatori)

```bash
git clone https://github.com/Message-Globe/messageglobe-wp.git wp-content/plugins/messageglobe
cd wp-content/plugins/messageglobe
composer install
```

Poi attiva **MessageGlobe per WordPress** dalla schermata Plugin.

## Configurazione

Apri il menu **MessageGlobe** in bacheca e incolla il tuo API token dalla
[dashboard sviluppatori](https://dashboard.messageglobe.com/developers). Un solo token autorizza
tutte le funzioni REST (SMS, contatti, liste, sender ID).

In produzione puoi tenere il token fuori dal database definendolo in `wp-config.php`: in questo caso
ha la precedenza ed è mostrato in sola lettura nell'interfaccia:

```php
define( 'MESSAGEGLOBE_API_TOKEN', 'XX|il-tuo-api-token' );
```

## Deliverability email (SMTP)

Attiva **Email** nelle impostazioni per instradare ogni `wp_mail()` attraverso MessageGlobe. Il
plugin configura il mailer di WordPress tramite l'hook `phpmailer_init` — non carica mai una seconda
libreria email — e può usare il **preset MessageGlobe** (host, porta e l'API token come password
SMTP) oppure il tuo server SMTP con cifratura TLS/SSL/nessuna. Un pulsante **Test connessione** si
collega e si autentica senza inviare, un pulsante **Invia email di test** conferma la consegna
end-to-end e gli errori vengono registrati nel log attività.

## Notifiche SMS

Attiva **SMS** e scegli un sender ID predefinito (caricato in tempo reale dal tuo account) e il
gateway:

- **HQ** — sender ID personalizzato e report di consegna.
- **LQ** — nessun mittente personalizzato, nessun report di consegna.

I messaggi vengono passati alla [coda asincrona](#come-funziona), così nulla di ciò che attivi
durante una richiesta di pagina aspetta l'API. I caratteri non-GSM (emoji, cirillico, …) sono
rilevati e inviati come Unicode automaticamente. Nelle impostazioni è disponibile un pulsante
**Invia SMS di test**.

## WooCommerce

Quando WooCommerce è attivo compaiono due funzioni aggiuntive:

- **SMS ordine al cliente** — un messaggio per stato dell'ordine (`processing`, `completed`,
  `on-hold`, `cancelled`, `refunded`), ciascuno con il proprio interruttore e template. Funziona sia
  con il checkout classico sia con quello a blocchi.
- **Avviso nuovo ordine allo staff** — un SMS a uno o più numeri dello staff alla creazione di un
  ordine.

I template supportano questi tag:

| Tag | Valore |
|---|---|
| `{order_number}` | Il numero d'ordine |
| `{order_id}` | L'id interno dell'ordine |
| `{order_status}` | Lo slug del nuovo stato |
| `{order_total}` | Totale ordine formattato |
| `{billing_first_name}` / `{billing_last_name}` | Nome del cliente |
| `{payment_method}` | Metodo di pagamento |
| `{currency}` | Valuta dell'ordine |
| `{site_title}` | Il nome del tuo sito |

## Sincronizzazione utenti → lista

Attiva **Sync**, scegli una lista MessageGlobe di destinazione (caricata in tempo reale dal tuo
account) e seleziona i **ruoli utente** da sincronizzare. Gli utenti corrispondenti vengono aggiunti
alla lista — con email, telefono e nome — alla **registrazione**, al **cambio ruolo** e
all'**aggiornamento del profilo**.

- Il numero di telefono viene letto da una chiave user-meta configurabile, con default
  `billing_phone` (dove lo salva WooCommerce).
- Ogni sincronizzazione è messa in coda via WP-Cron ed è **idempotente per lista**: un utente viene
  aggiunto una sola volta, non a ogni salvataggio del profilo.
- **Sincronizza subito tutti gli utenti esistenti** — un'azione in blocco con un clic nella scheda
  Sync aggiunge alla lista ogni utente nel raggio d'azione con una barra di avanzamento, saltando
  chi è già sincronizzato.

## Impostazioni e log

È tutto sotto un'unica pagina **MessageGlobe** con schede per **Connessione, SMS, Email,
WooCommerce, Sync** e **Log**. Ogni scheda di messaggistica ha un pulsante di test e la scheda Log
mostra l'attività recente di SMS/email/sync con gli stati, così vedi esattamente cosa è stato
inviato e perché qualcosa è fallito. Una tabella separata **Esecuzioni in background recenti**
riassume ogni svuotamento della coda SMS asincrona (elaborati / inviati / falliti / rimanenti).

## Sicurezza e privacy

- L'API token è salvato in una singola opzione oppure, in modo più sicuro, nella costante
  `MESSAGEGLOBE_API_TOKEN`, che non viene mai scritta nel database.
- Tutti i form e le azioni AJAX in area admin sono protetti da nonce e verifica dei permessi
  (`manage_options`); ogni output è preservato con escaping e ogni input sanificato.
- Il plugin invia a MessageGlobe soltanto i dati che configuri (testo dei messaggi, numeri dei
  destinatari e i campi contatto che scegli di sincronizzare). Nulla viene condiviso con altre terze
  parti.

## Come funziona

- **SDK PHP, trasporto WordPress.** Le chiamate REST passano dall'SDK PHP di MessageGlobe incluso,
  collegato a un trasporto che usa `wp_remote_request()` — così le richieste rispettano proxy e
  filtri HTTP del tuo sito, invece del cURL grezzo.
- **Asincrono per default.** SMS e sincronizzazioni contatti in uscita vengono scritti in una piccola
  tabella dedicata e smaltiti da un worker WP-Cron con retry/backoff, così le richieste di pagina
  ritornano subito.
- **Modulare.** Ogni funzionalità (Email, SMS, WooCommerce, Sync, Admin) è un modulo isolato,
  collegato in un unico punto di composizione.

## Sviluppo

```bash
composer install          # installa le dipendenze
php -l messageglobe.php    # lint (ripeti per file, o usa un linter)
```

Le release sono automatiche: il push di un tag `vX.Y.Z` esegue
[`.github/workflows/release.yml`](.github/workflows/release.yml), che installa le dipendenze di
produzione, costruisce lo zip installabile nativamente `messageglobe-X.Y.Z.zip` (un albero con
cartella radice `messageglobe/` e `vendor/` incluso) e lo allega alla GitHub Release. Un'esecuzione
manuale del workflow carica lo stesso zip come artifact per i test, e un input opzionale rimuove il
PHPMailer incluso (WordPress core lo fornisce già) per evitare collisioni tra classi.

## FAQ

**Serve un account MessageGlobe?**
Sì — creane uno e copia il tuo API token dalla dashboard sviluppatori.

**Funziona senza WooCommerce?**
Sì. Email SMTP, SMS e sincronizzazione utenti funzionano su qualsiasi sito WordPress; le funzioni
WooCommerce compaiono semplicemente quando WooCommerce è attivo.

**Dove viene salvato il mio API token?**
In un'opzione di WordPress, oppure nella costante `MESSAGEGLOBE_API_TOKEN` in `wp-config.php`
(consigliato in produzione), che non viene mai scritta nel database.

**SMS/email non partono immediatamente.**
Vengono messi in coda ed elaborati da WP-Cron. Su siti a basso traffico conviene un cron di sistema
reale che chiami `wp-cron.php` per una consegna tempestiva.

## Licenza

Distribuito con licenza [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html). Costruito
sull'[SDK PHP di MessageGlobe](https://github.com/Message-Globe/messageglobe-php), rilasciato con
licenza MIT.
