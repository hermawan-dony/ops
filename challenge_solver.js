/**
 * WAF / Anti-Bot Challenge Solver for ops.framas.co.id
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const cookieFile = process.argv[2] || path.join(__dirname, '.deploy_cookie');
const targetUrl = process.argv[3] || 'https://ops.framas.co.id/deploy_receiver.php';
const userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

async function solve() {
  try {
    const res = await fetch(targetUrl, {
      headers: { 'User-Agent': userAgent }
    });
    const html = await res.text();
    if (!html.includes('One moment, please')) {
      // No challenge active
      process.exit(0);
    }

    const scriptMatches = html.match(/<script>([\s\S]*?)<\/script>/gi);
    if (!scriptMatches || scriptMatches.length < 2) {
      console.error('Challenge script not found');
      process.exit(1);
    }
    const code = scriptMatches[1].replace(/<\/?script>/gi, '');

    const challengeData = await new Promise((resolve, reject) => {
      function createElement(tag) {
        const el = {
          tagName: tag,
          children: [],
          style: {},
          appendChild(child) { el.children.push(child); },
          submit() {
            const params = new URLSearchParams();
            for (const c of el.children) {
              if (c.name) params.append(c.name, c.value);
            }
            resolve({
              action: el.action,
              method: el.method,
              params: params.toString()
            });
          }
        };
        return el;
      }

      const proto = {};
      const dummyItem = { __proto__: proto };
      const dummyList = [dummyItem];
      dummyList.__proto__ = proto;

      const context = {
        window: {},
        document: {
          getElementById: () => createElement('div'),
          createElement: createElement,
          addEventListener: (event, fn) => { fn(); },
          attachEvent: (event, fn) => { fn(); }
        },
        navigator: {
          userAgent: userAgent,
          appVersion: '5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
          language: 'en-US',
          languages: ['en-US', 'en'],
          plugins: dummyList,
          mimeTypes: dummyList
        },
        PluginArray: { prototype: proto },
        Plugin: { prototype: proto },
        MimeTypeArray: { prototype: proto },
        MimeType: { prototype: proto },
        setTimeout: (fn) => fn(),
        setInterval: () => {},
        URLSearchParams: URLSearchParams,
        XMLHttpRequest: function() {},
        console: { log: () => {}, error: () => {} }
      };
      context.window = context;
      context.window.document = context.document;
      context.window.navigator = context.navigator;
      context.window.outerWidth = 1920;
      context.window.outerHeight = 1080;

      vm.createContext(context);
      try {
        vm.runInContext(code, context);
      } catch (err) {
        reject(err);
      }
    });

    if (!challengeData) {
      console.error('Failed to parse challenge');
      process.exit(1);
    }

    const clearUrl = 'https://ops.framas.co.id' + challengeData.action + '?' + challengeData.params;
    const clearRes = await fetch(clearUrl, {
      headers: { 'User-Agent': userAgent },
      redirect: 'manual'
    });

    const cookieHeader = clearRes.headers.get('set-cookie');
    if (cookieHeader) {
      const cookieVal = cookieHeader.split(';')[0].trim();
      fs.writeFileSync(cookieFile, cookieVal, 'utf8');
      console.log('Solved: ' + cookieVal);
      process.exit(0);
    } else {
      console.error('No Set-Cookie received from clearance endpoint');
      process.exit(1);
    }
  } catch (e) {
    console.error('Error solving challenge:', e.message);
    process.exit(1);
  }
}

solve();
