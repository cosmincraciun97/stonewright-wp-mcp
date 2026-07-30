---
title: Elementor FAQ
source_url: https://elementor.com/help/elementor-faq/
fetched_at: 2026-05-23T00:11:00.780Z
content_hash: sha256-e38334193a6d16a4bb9dbd1bc8f7debcf4f2e0d7a233c3875de9e706bcd697ac
applies_to: [editor:v3]
related_widgets: [heading, button, image, video, icon]
harvest_source: gemini-browser
---

## Purpose
Elementor Editor General I only need to build one website. Which plan should I get?

## Use this when
- For Elementor One, “License not found” usually means the site is connecting through the wrong entry point, not that your subscription is missing
- In the left menu, go to Elementor → Home(do not use the Plugins screen Connect & Activate button)
- Select any other plugins you want under the One plan
- I already have Elementor One, but I’m seeing a Get One

## Settings highlights
- I’m not entirely happy with the AI – generated content on my site.
- Is there any way to roll – back, or test the widgets created with Angie to avoid problems/downtimes on my site?
- Request Team Access – Ask the site owner to add you as a Team Member within their Elementor account. This will give you your own login to manage hosting settings safely. Guide: Managing Team Members.
- Elementor Host runs on a high – performance NGINX stack, which means it does not use or support .htaccess files. Additionally, max_input_vars is a server-level PHP configuration that cannot be modified manually by users in a managed environment.
- If the issue persists, certain third – party plugins can conflict with Elementor and Elementor Pro. Try disabling them one by one temporarily.
- It looks like you’ve run into a “double – caching” issue, which happens when a third-party caching plugin conflicts with your server.
- Use the Elementor Tool – Go to Elementor > Tools > Replace URL.
- Enter the Domains – Enter your Old URL and your New URL exactly as they appear (including https://), then click Replace URL.

## Limits / gotchas
- You see an error in Site Planner about too many pages because it supports up to 25 pages per project. If your brief or sitemap exceeds this limit, reduce the number of pages to proceed. Trim your project to 25 pages or fewer to resolve the error.
- I want to increase my Memory Limit Request to 512 MB
- Good news! Your Elementor Hosting plan is already configured with a PHP Memory Limit of 1024 MB by default—which is double the 512 MB you requested.
- Since your limit is already set very high, are you seeing a specific error message or experiencing slowness? This might indicate a plugin conflict rather than a lack of memory.
