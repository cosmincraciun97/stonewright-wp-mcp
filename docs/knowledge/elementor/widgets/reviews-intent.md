---
title: Reviews Widget
source_url: https://elementor.com/widgets/reviews-widget/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:reviews]
related_widgets: [testimonial, testimonial-carousel, star-rating]
---

## Purpose

Display customer testimonials and reviews in a sliding carousel format with star ratings and social media platform icons. The Reviews widget combines the visual credibility of star ratings with the platform attribution of social icons (Google, Facebook, Yelp, etc.) and animated carousel display, making it suitable for aggregated social proof sections. Requires Elementor Pro.

## Use this when

- Showcasing customer feedback on landing pages or service pages in a rotating carousel
- Displaying multiple reviews compactly without requiring a full-width testimonial section
- Building trust signals for potential customers with platform-attributed reviews (shows which site the review came from)
- Highlighting star-rated reviews with social proof from recognizable platforms
- Aiming to increase conversion rates through demonstrated multi-source customer satisfaction

## Settings highlights

- **Review items**: Repeater list; each item has: review text, reviewer name, rating (1–5), social icon, link
- **Social icon**: Per-review icon indicating the review source platform (Google, Facebook, Yelp, TripAdvisor, etc.)
- **Star rating**: Per-review 1–5 star value displayed as filled stars
- **Auto-play**: Toggle animated carousel with configurable interval timing
- **Slides to show**: Number of review cards visible simultaneously
- **Slides to scroll**: How many cards advance per carousel step
- **Loop**: Infinite loop toggle for continuous carousel rotation
- **Reviewer image**: Optional avatar per review item
- **Typography (review text)**: Font controls for the review body
- **Typography (reviewer name)**: Separate controls for the name label

## Limits / gotchas

- Requires Elementor Pro subscription; not available in the free version
- Auto-play carousel timing should be tested for UX — too fast prevents reading; 4–6 seconds is typical
- Social icon accuracy depends on manually selecting the correct platform icon per review item; there is no automated scraping from review platforms
- Limited to WordPress environments with Elementor Pro active; the widget does not pull live data from Google/Facebook review APIs — reviews are entered manually in the repeater
