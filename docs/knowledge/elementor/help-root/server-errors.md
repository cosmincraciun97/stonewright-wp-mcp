---
title: Server Errors or Contact Forms Not Sending Emails
source_url: https://elementor.com/help/server-errors/
fetched_at: 2026-05-23T00:08:56.235Z
content_hash: sha256-5156b1d6f53999acecfb73c7b0937bea9987e1dd7e4b288487bd5b8cfd33c67d
applies_to: [help-root]
related_widgets: [icon]
harvest_source: gemini-browser
---

## Purpose
Elementor uses WordPress’ wp_mail function to send emails. Your web host takes the sent email, processes it, and sends it. Web hosting servers can deactivate the PHP function responsible for email transmission, preventing the sending of emails. This is a precautionary measure meant to prevent the misuse of the server for spamming purposes, ensuring that users do not use the hosting platform to send spam emails.

## Use this when
- Elementor uses WordPress’ wp_mail function to send emails
- Your web host takes the sent email, processes it, and sends it
- Web hosting servers can deactivate the PHP function responsible for email transmission, preventing the sending of emails
- StepDescriptionSet the form’s “From” email address to the same domain as your website If your site is example

## Settings highlights
- Use an SMTP server – SMTP stands for “Simple Mail Transfer Protocol”. This is an email server that routes your email in forms into the inbox of your listed customers. It is an external email server (for example, Gmail can be used as an SMTP server) that ensures your emails get delivered faster and helps prevent your email from ending up in users’ spam folders.
- Use a tool such as Site Mailer – When a user submits a form, two email notifications are often sent: one to the user as confirmation and one to you or your team. These emails may end up in the spam folder, especially now that providers like Gmail are increasingly strict about email reputation and authentication. To address this, we recommend using a tool like Site Mailer, which routes all site emails through a dedicated external service rather than your hosting server.
- Note – Essential, Advanced, Advanced Solo, and Elementor App subscriptions include access to the Elementor chatbot. Expert, Agency, Elementor One, Elementor Hosting, and Priority Support plans also include access to the Elementor support team.
- Content options – Configure general content, title, tags, and icons.
- Style settings – Customize colors, borders, background, padding, and typography.
- Advanced features – Apply custom CSS classes, ID, and responsiveness properties.

## Limits / gotchas
- This requires an account on SendGrid, Mailgun, or any other email hosting that the user will use for testing.
- Essential, Advanced, Advanced Solo, and Elementor App subscriptions include access to the Elementor chatbot. Expert, Agency, Elementor One, Elementor Hosting, and Priority Support plans also include access to the Elementor support team.
