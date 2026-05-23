import { chromium } from 'playwright';

async function run() {
  console.log('Launching browser...');
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  
  const url = 'https://elementor.com/help/accordion-widget/';
  console.log(`Navigating to ${url}...`);
  await page.goto(url, { waitUntil: 'domcontentloaded' });
  
  console.log('Waiting for content...');
  // Wait for article body. Let's check some selectors or wait 5 seconds.
  await page.waitForTimeout(5000);
  
  // Let's print out the page title and some paragraph texts.
  const title = await page.title();
  const h1 = await page.$eval('h1', el => el.textContent).catch(() => 'No H1');
  console.log('Page Title:', title);
  console.log('H1:', h1);
  
  // Let's get the text content of the article body.
  const content = await page.evaluate(() => {
    // Let's look for common content containers
    const article = document.querySelector('article') || document.querySelector('.help-article-body') || document.querySelector('.elementor-widget-container') || document.body;
    return article ? article.innerText : 'No content';
  });
  
  console.log('Content Snippet:', content.substring(0, 1000));
  
  await browser.close();
}

run().catch(console.error);
