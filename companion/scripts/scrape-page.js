import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const url = process.argv[2];
const outputFile = process.argv[3];

if (!url) {
  console.error('Usage: node scrape-page.js <URL> [outputFile]');
  process.exit(1);
}

async function scrape() {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
  });
  
  const page = await context.newPage();
  
  // Block assets we don't need to speed up loading
  await page.route('**/*', (route) => {
    const type = route.request().resourceType();
    if (['image', 'media', 'font', 'stylesheet'].includes(type)) {
      route.abort();
    } else {
      route.continue();
    }
  });

  try {
    console.error(`Navigating to ${url}...`);
    // Elementor pages might take time, wait up to 20s
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 20000 });
    
    // Wait up to 10s for primary article containers or body
    await page.waitForFunction(() => {
      return document.querySelector('article') || 
             document.querySelector('.help-article-body') || 
             document.querySelector('.entry-content') || 
             document.querySelector('.elementor-widget-theme-post-content') ||
             document.querySelector('.markdown') || 
             document.querySelector('main') ||
             document.body;
    }, { timeout: 10000 }).catch(() => {});

    // Let the JS SPA render a bit more if needed
    await page.waitForTimeout(2000);

    const result = await page.evaluate((targetUrl) => {
      // Find the best content container
      const selectors = [
        'article',
        '.help-article-body',
        '.entry-content',
        '.elementor-widget-theme-post-content',
        '.markdown',
        'main',
        '#content',
        '.elementor-widget-container'
      ];
      
      let container = null;
      for (const selector of selectors) {
        const el = document.querySelector(selector);
        if (el && el.innerText && el.innerText.trim().length > 200) {
          container = el;
          break;
        }
      }
      
      if (!container) {
        container = document.body;
      }

      // Extract details
      const h1El = document.querySelector('h1');
      const h1 = h1El ? h1El.innerText.trim() : '';
      const title = document.title ? document.title.trim() : '';
      
      // Extract links to find related widgets/articles
      const links = Array.from(document.querySelectorAll('a'))
        .map(a => ({ href: a.href, text: a.innerText.trim() }))
        .filter(l => l.href && l.href.startsWith('https://elementor.com/'));

      // Keep only headings, paragraphs, lists, table content, code blocks from content
      // Remove sitemaps, headers, footers, navs inside container if it fell back to body
      const clone = container.cloneNode(true);
      
      // Remove elements we definitely don't want
      const excludeSelectors = [
        'header', 'footer', 'nav', '.nav', '.menu', '.footer', '.header',
        '.sidebar', '#sidebar', '.widget-area', '.comments-area',
        '.elementor-location-header', '.elementor-location-footer',
        '.help-search-box', '.help-navigation', '.related-articles',
        'script', 'style', 'noscript', 'iframe'
      ];
      
      for (const sel of excludeSelectors) {
        clone.querySelectorAll(sel).forEach(el => el.remove());
      }

      return {
        title: title || h1,
        h1: h1 || title,
        text: clone.innerText.trim(),
        html: clone.innerHTML,
        links: links.slice(0, 100) // cap to 100 links
      };
    }, url);

    result.url = url;
    result.fetchedAt = new Date().toISOString();

    if (outputFile) {
      fs.writeFileSync(outputFile, JSON.stringify(result, null, 2), 'utf8');
      console.log(`Successfully scraped and saved to ${outputFile}`);
    } else {
      console.log(JSON.stringify(result));
    }
  } catch (err) {
    console.error(`Error scraping ${url}:`, err.message);
    process.exit(1);
  } finally {
    await browser.close();
  }
}

scrape();
