import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const batchFile = process.argv[2];

if (!batchFile || !fs.existsSync(batchFile)) {
  console.error('Usage: node scrape-batch.js <batch-file-path>');
  process.exit(1);
}

const batchName = path.basename(batchFile, '.json');
const items = JSON.parse(fs.readFileSync(batchFile, 'utf8'));

const outputDir = path.join('D:\\Work\\stonewright-wp-mcp\\companion\\scripts\\batches\\scraped', batchName);
if (!fs.existsSync(outputDir)) {
  fs.mkdirSync(outputDir, { recursive: true });
}

// Helper to get slug
function getSlug(url) {
  const parts = url.replace(/\/$/, '').split('/');
  const last = parts[parts.length - 1];
  return last.toLowerCase().replace(/_/g, '-');
}

async function scrapeUrl(browser, item, index) {
  const slug = getSlug(item.url);
  const outputFile = path.join(outputDir, `${slug}.json`);
  
  // Skip if already scraped
  if (fs.existsSync(outputFile)) {
    console.log(`[${index + 1}/${items.length}] Already scraped: ${item.url}`);
    return;
  }

  const context = await browser.newContext({
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
  });
  
  const page = await context.newPage();
  
  // Block assets to speed up
  await page.route('**/*', (route) => {
    const type = route.request().resourceType();
    if (['image', 'media', 'font', 'stylesheet'].includes(type)) {
      route.abort();
    } else {
      route.continue();
    }
  });

  try {
    console.log(`[${index + 1}/${items.length}] Scraping ${item.url}...`);
    // Wait up to 20s for page load
    await page.goto(item.url, { waitUntil: 'domcontentloaded', timeout: 20000 });
    
    // Wait for dynamic SPA content
    await page.waitForFunction(() => {
      return document.querySelector('article') || 
             document.querySelector('.help-article-body') || 
             document.querySelector('.entry-content') || 
             document.querySelector('.elementor-widget-theme-post-content') ||
             document.querySelector('.markdown') || 
             document.querySelector('main') ||
             document.body;
    }, { timeout: 10000 }).catch(() => {});

    await page.waitForTimeout(2000);

    const result = await page.evaluate(() => {
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

      const h1El = document.querySelector('h1');
      const h1 = h1El ? h1El.innerText.trim() : '';
      const title = document.title ? document.title.trim() : '';
      
      const links = Array.from(document.querySelectorAll('a'))
        .map(a => ({ href: a.href, text: a.innerText.trim() }))
        .filter(l => l.href && l.href.startsWith('https://elementor.com/'));

      const clone = container.cloneNode(true);
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
        links: links.slice(0, 100)
      };
    });

    result.url = item.url;
    result.slug = slug;
    result.hub = item.hub;
    result.applies_to = item.applies_to;
    result.fetchedAt = new Date().toISOString();

    fs.writeFileSync(outputFile, JSON.stringify(result, null, 2), 'utf8');
    console.log(`[${index + 1}/${items.length}] Saved: ${slug}.json`);
  } catch (err) {
    console.error(`[${index + 1}/${items.length}] Error scraping ${item.url}:`, err.message);
    
    // Save a fail placeholder so we know it errored (likely 404 or block)
    const errResult = {
      url: item.url,
      slug,
      hub: item.hub,
      applies_to: item.applies_to,
      error: err.message,
      title: item.title || 'Error',
      h1: item.title || 'Error',
      text: '',
      links: [],
      fetchedAt: new Date().toISOString()
    };
    fs.writeFileSync(outputFile, JSON.stringify(errResult, null, 2), 'utf8');
  } finally {
    await page.close();
  }
}

async function run() {
  console.log(`Starting batch scraping for ${batchName} (${items.length} items)...`);
  const browser = await chromium.launch({ headless: true });
  
  // We can scrape with a concurrency of 3 to speed things up
  const CONCURRENCY = 3;
  const queue = [...items.entries()];
  
  async function worker() {
    while (queue.length > 0) {
      const [index, item] = queue.shift();
      await scrapeUrl(browser, item, index);
    }
  }

  const workers = Array.from({ length: CONCURRENCY }, () => worker());
  await Promise.all(workers);
  
  await browser.close();
  console.log(`Finished batch scraping for ${batchName}.`);
}

run().catch(console.error);
