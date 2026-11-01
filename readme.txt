=== DEV3 AI Shopping Assistant & Chatbot for WooCommerce ===
Contributors: dev3tech
Tags: chatbot, live chat, ai assistant, order tracking, woocommerce
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Requires Plugins: woocommerce
WC requires at least: 8.0
WC tested up to: 10.3
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A shopping assistant chat that knows your catalog, orders and store policies. It recommends products, answers "where is my order", adds to the cart and hands over to your team. DEV3 AI (Google Gemini, your own key) makes it conversational.

== Description ==

Shoppers ask, the assistant answers from your live WooCommerce data: product cards with prices, stock and an Add to cart button, order status with tracking numbers, and your shipping, returns and payment answers. It is fully usable without AI (guided mode) and gets smarter with a Google Gemini key.

= Core features (no AI needed) =
* Floating chat with launcher, greeting bubble, starter buttons, auto-open and page targeting; inline chat block and [dev3sa_chat] shortcode; [dev3sa_chat_button] opens the chat from any button or link (#dev3sa-chat).
* Built for phones: a full-screen sheet with safe-area insets that stays above the on-screen keyboard, 44px touch targets, back button closes it, no page scroll behind it.
* Product finder: keyword search over titles, SKUs, categories, tags and attributes; understands "under 50", "between 20 and 40", "on sale", "best sellers", "new".
* Add to cart from the chat (simple products), with View cart / Checkout / Undo; mini-carts refresh (classic and block).
* Order tracking: customers see their recent orders; guests enter order number + billing email. Status timeline, items, total and tracking numbers (Advanced Shipment Tracking and common meta keys). Addresses and payment details are never shown.
* Knowledge base: FAQ, shipping, returns and payment answers; one-click import from your refund page, terms, privacy page, shipping zones and payment methods.
* Talk to a person: handoff form emails your team with the transcript; WhatsApp and phone buttons; business hours with an away message.
* Conversations inbox with transcripts, statuses, email replies, CSV export; ratings (helpful / not helpful) on every answer.
* Dashboard: conversations, answer rate, add-to-carts, revenue from chat (attributed to orders, HPOS-safe), satisfaction, top and unanswered questions.
* Privacy: consent line, transcript retention, hashed IPs, GDPR export and erase.

= DEV3 AI features (optional, your own Google Gemini key) =
* AI shopping chat with retrieval: semantic product and knowledge search (embeddings) plus keyword search; replies in the shopper's language; recommends only real products from your catalog; prices always come from WooCommerce.
* "Add the blue mug to my cart" adds the right product (validated server-side, undo available).
* Build my knowledge base: answers drafted from your policies and settings.
* Knowledge gaps: groups questions the assistant could not answer and drafts the missing answers.
* Conversation insights: themes, demand for products you don't sell, sentiment and actions.
* Persona writer: name, greeting, starter buttons and voice instructions in your brand voice.
* Test console: chat as a shopper and see why each answer was given.

Every admin AI result is a draft you accept, edit or reject. Storefront AI has a kill switch, a monthly budget cap, caching, a per-conversation AI limit and a rate limit, and falls back to the guided assistant whenever the AI is unavailable.

== Installation ==

1. Upload the zip in Plugins › Add New › Upload Plugin and activate (WooCommerce must be active).
2. Open "DEV3 AI Assistant" in the admin menu.
3. Knowledge base › Import from store.
4. Optional: API Config › add your Google Gemini API key, then Knowledge base › Build index with DEV3 AI.

== Frequently Asked Questions ==

= Do I need an AI key? =
No. Without a key the assistant runs in guided mode (product finder, order tracking, knowledge base, handoff).

= Is my API key exposed? =
No. The key is stored encrypted on your server and every AI call happens server-side.

= Does it work with page caching? =
Yes. The widget sends no nonce for guests and loads its data only when opened.

= Does it work with the block cart and checkout and HPOS? =
Yes, both are declared compatible and attribution uses WooCommerce order CRUD.

== Privacy ==

When DEV3 AI is enabled, the shopper's message, the relevant product facts and knowledge entries are sent to Google Gemini (Google LLC) to write the answer. A privacy-policy text is added under Settings › Privacy. Transcripts are stored in your database for the retention period you choose (or one day when storage is off).

== Changelog ==

= 1.0.0 =
* First release.
