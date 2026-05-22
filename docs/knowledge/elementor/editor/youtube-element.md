---
title: YouTube element
source_url: https://elementor.com/help/youtube-element/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

The YouTube element is a V4 atomic element for embedding YouTube videos with player controls, autoplay, loop, and mute options via the YouTube IFrame API. Unlike the V3 Video widget (which supported YouTube, Vimeo, and self-hosted in a single widget with grouped controls), the V4 YouTube element is a focused atomic component dedicated to YouTube embeds with direct prop-to-attribute mapping.

## Use this when

- Embedding a YouTube video in a hero, media section, or testimonial layout
- Creating an autoplay+mute looping background video (combined with background image fallback)
- Displaying a video gallery where each YouTube embed is a separate element
- Using dynamic tags to bind the video URL to a custom field in Loop templates
- Controlling YouTube player behavior (hide controls, start at timestamp, related videos off)

## Settings highlights

- **url** prop — full YouTube URL or video ID; supports dynamic tags for template-driven video sources
- **autoplay** prop — boolean; requires `mute: true` for browser autoplay policy compliance
- **mute** prop — boolean; mutes audio on load (required for autoplay)
- **loop** prop — boolean; loops video when it ends (YouTube API repeats the video)
- **controls** prop — boolean; show/hide YouTube player controls bar
- **start** prop — timestamp in seconds to start playback (YouTube `start` parameter)
- **end** prop — timestamp in seconds to stop playback (YouTube `end` parameter)
- **rel** prop — `0` to suppress related videos from other channels at end; `1` to allow
- **Aspect ratio** — 16:9, 4:3, or custom via width/height Size props
- **Privacy enhanced mode** — uses `youtube-nocookie.com` domain for GDPR compliance

## Limits / gotchas

- Browser autoplay policy requires `mute: true` for `autoplay: true` to work; unmuted autoplay is blocked in Chrome, Firefox, and Safari
- YouTube IFrame API requires an internet connection; video does not play offline or in strict Content Security Policies that block YouTube domains
- V4 YouTube element is distinct from the V3 Video widget; existing pages using the V3 Video widget remain as-is after migration
- Privacy enhanced mode (`youtube-nocookie.com`) still sets a cookie on first play interaction; for full GDPR compliance, implement a consent gate
