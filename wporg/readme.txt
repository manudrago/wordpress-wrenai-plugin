=== Ideagency AI Data Dashboards ===
Contributors: manudrago
Tags: analytics, dashboard, ai, charts, woocommerce
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ask your WordPress and WooCommerce data anything in plain English and get charts, tables and dashboards in wp-admin.

== Description ==

Ideagency AI Data Dashboards adds a question box to wp-admin. Type "how many orders did we take
each month this year?" and get a chart, a table and a CSV in seconds, then save the answer as a
panel on a dashboard.

How it works: the plugin sends the question and your database *structure* (table and column
names, types, and the descriptions you write) to the language model you choose. The model turns
the question into SQL; the plugin checks that SQL against a strict read-only guard and runs it on
your own database. To design the chart, a small sample of the result rows (30 by default) is sent
to the model as well. Charts are drawn as inline SVG, with no external chart library and no CDN.

Setup is one API key: Google AI Studio and Groq have free tiers, OpenAI is pay-as-you-go, and any
OpenAI-compatible endpoint works, including Ollama and LM Studio running on your own hardware, in
which case nothing leaves your network.

= What you get =

* Plain-English questions under AI Data Dashboards → Ask, with follow-ups ("and last year?") and
  example questions.
* Charts you choose: columns, bars, line, area, pie and KPI, one click apart. Time always runs
  forwards, long labels turn the chart on its side, colours follow your theme.
* Tables, SQL on view and CSV export.
* Saved dashboards that re-run live, with as many panels as you like.
* WooCommerce ready: share the order and product tables and ask about sales, customers and stock.

= Safe by design =

* The model is treated as an untrusted source of SQL. Every statement must be a single
  SELECT/WITH, may only touch the tables you shared, may not reference blocked columns or system
  schemas, and always carries a LIMIT.
* Sensitive columns (passwords, emails, session tokens) are blocked and masked by default.
* Define IADD_DB_USER / IADD_DB_PASSWORD in wp-config.php to run every query as a read-only MySQL
  user.
* Asking requires a capability (default: edit_posts), and changing a dashboard requires edit
  rights over it. Every question and statement can be logged.

= More from Ideagency =

Separate plugins with extra features (scheduled email reports, dashboards on public pages, white
label for agencies) are available at [ideagency.co.uk](https://ideagency.co.uk/our-plugins/).
This plugin is complete on its own, has no locked features and never contacts that shop.

== Installation ==

1. Install and activate the plugin.
2. AI Data Dashboards → Settings: choose a provider, paste an API key (a free one from
   aistudio.google.com/apikey works) and press "Test connection".
3. AI Data Dashboards → Data & schema: pick the tables to share and add a few lines of business
   context.
4. AI Data Dashboards → Ask, and ask something.

== Frequently Asked Questions ==

= Which model does this need? =

Any of: Google AI Studio (free tier, the default), Groq (free tier), OpenAI, or anything that
speaks the OpenAI chat-completions API, including Ollama and LM Studio on your own hardware.

= What leaves my site? =

The question, the schema (table and column names, types, relationships and your descriptions) and
a sample of the result rows used to design the chart: 30 by default, adjustable with the
iadd_chart_sample_rows filter. Never the rest of your data: the SQL runs here. With a local model
(Ollama, LM Studio) nothing leaves your network.

= Can I use a Wren AI service instead? =

Yes. Pick that engine in Settings. It talks to the REST API of wren-ai-service (Wren AI
self-hosted or Wren AI Cloud). This plugin is not affiliated with Wren AI.

== External services ==

This plugin sends data to an external service only when you configure one, and only when someone
asks a question, tests the connection or lists models in Settings. Which service depends on the
provider you choose:

* **Google AI Studio (Gemini API)**, the default provider: generativelanguage.googleapis.com.
  Sent: the question, the schema of the tables you shared and up to 30 result rows.
  [Terms](https://ai.google.dev/gemini-api/terms), [Privacy](https://policies.google.com/privacy).
* **Groq**: api.groq.com. Same data.
  [Terms](https://groq.com/terms-of-use/), [Privacy](https://groq.com/privacy-policy/).
* **OpenAI**: api.openai.com. Same data.
  [Terms](https://openai.com/policies/terms-of-use/), [Privacy](https://openai.com/policies/privacy-policy/).
* **An OpenAI-compatible endpoint of your choice** (OpenRouter, Ollama, LM Studio…): the URL you
  enter. Same data; the terms are those of whoever runs that endpoint.
* **Wren AI**, only if you pick the Wren AI engine: the endpoint you enter, self-hosted or Wren AI
  Cloud. Sent: the question and the schema. [Wren AI](https://getwren.ai/),
  [Privacy](https://getwren.ai/privacy-policy).
  Settings can also generate a pairing command for your own server; the installer it runs is
  downloaded by you from a public GitHub repository. The plugin only shows the command and links
  to the guide; it never downloads or runs anything itself.

No data is sent anywhere until a provider and key are configured.

== Changelog ==

= 2.5.1 =
* First release on WordPress.org.
