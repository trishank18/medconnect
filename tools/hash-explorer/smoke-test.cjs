// Dependency-free UI smoke test. Requires Node 20+ and Chrome/Edge.
const { spawn, execFileSync } = require('node:child_process');
const { pathToFileURL } = require('node:url');
const path = require('node:path');
const fs = require('node:fs');
const assert = require('node:assert/strict');

const chrome = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const port = 9337;
const php = process.env.PHP_PATH || 'C:\\xampp\\php\\php.exe';
const webPort = 8097;
const profile = path.join(require('node:os').tmpdir(), `hash-explorer-chrome-${process.pid}`);
const child = spawn(chrome, ['--headless=new','--disable-gpu',`--remote-debugging-port=${port}`,`--user-data-dir=${profile}`,'about:blank'], {stdio:'ignore',windowsHide:true});
const server = spawn(php,['-S',`127.0.0.1:${webPort}`,'-t',__dirname],{stdio:'ignore',windowsHide:true});
let socket, nextId=0; const pending=new Map();
function send(method,params={}) { const id=++nextId; socket.send(JSON.stringify({id,method,params})); return new Promise((resolve,reject)=>pending.set(id,{resolve,reject})); }
async function evaluate(expression) { const r=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true}); if(r.exceptionDetails) throw Error(r.exceptionDetails.text); return r.result?.value; }
async function waitForTarget(){for(let i=0;i<60;i++){try{const list=await fetch(`http://127.0.0.1:${port}/json/list`).then(r=>r.json());const page=list.find(x=>x.type==='page');if(page)return page;}catch{} await new Promise(r=>setTimeout(r,250));}throw Error('Chrome did not start');}
async function run(){
  let ready=false;for(let i=0;i<50;i++){try{await fetch(`http://127.0.0.1:${webPort}/api.php?action=capabilities`);ready=true;break;}catch{}await new Promise(r=>setTimeout(r,200));}assert.ok(ready,'PHP helper should start');
  const page=await waitForTarget();socket=new WebSocket(page.webSocketDebuggerUrl);
  await new Promise((resolve,reject)=>{socket.onopen=resolve;socket.onerror=reject;});
  socket.onmessage=e=>{const m=JSON.parse(e.data);if(m.id&&pending.has(m.id)){const p=pending.get(m.id);pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}};
  await send('Page.navigate',{url:`http://127.0.0.1:${webPort}/index.html`});await new Promise(r=>setTimeout(r,700));
  await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1150,deviceScaleFactor:1,mobile:false});
  await evaluate(`(async()=>{const t=document.querySelector('#plainInput');t.value='Hello World';t.dispatchEvent(new Event('input'));document.querySelector('#generateBtn').click();while(document.querySelector('#generationStatus').textContent==='WORKING…')await new Promise(r=>setTimeout(r,10));document.querySelector('#text-hash').scrollIntoView();})()`);
  await new Promise(r=>setTimeout(r,150));const preview=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});fs.writeFileSync(path.join(__dirname,'preview.png'),Buffer.from(preview.data,'base64'));
  const expectedHash=process.env.PHP_HASH || execFileSync('C:\\xampp\\php\\php.exe',['-r',"echo password_hash('demo-only', PASSWORD_BCRYPT);"],{encoding:'utf8'}).trim();
  const bcrypt='$2y$10$sVtK1y05Jsk4hSsG5krjUOcDJW3c4loF13byj7Up.Z8fFoDdzxe1S';
  const cases=[
    ['supplied bcrypt',bcrypt,'bcrypt (likely)','10 · 2^10'],
    ['PHP generated bcrypt',expectedHash,'bcrypt (likely)','10 · 2^10'],
    ['MD5','5f4dcc3b5aa765d61d8327deb882cf99','MD5-shaped digest','Not applicable'],
    ['SHA-256','2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824','SHA-256-shaped digest','Not applicable'],
    ['malformed','not-a-valid-hash','Awaiting input',null],
    ['empty','', 'Awaiting input',null]
  ];
  for(const [label,input,algo,cost] of cases){
    const got=await evaluate(`(()=>{const x=document.querySelector('#hashInput');x.value=${JSON.stringify(input)};x.dispatchEvent(new Event('input'));document.querySelector('#analyzeBtn').click();return {algo:document.querySelector('#algoName').textContent,cost:document.querySelector('#costValue').textContent,error:document.querySelector('#analyzerError').textContent,hidden:document.querySelector('#analyzerError').hidden}})()`);
    assert.equal(got.algo,algo,`${label}: algorithm`);if(cost)assert.equal(got.cost,cost,`${label}: cost`);if(label==='malformed')assert.match(got.error,/Unrecognized|malformed/i);if(label==='empty')assert.match(got.error,/Enter a hash/i);console.log(`PASS ${label}`);
  }
  const sqlCases=[
    ['SQL variable with hash',`SET @seed_password_hash = '${bcrypt}';\nINSERT INTO doctors (fullname, username, password) VALUES ('Example Doctor', 'doctor1', @seed_password_hash);`,/Resembles bcrypt/,/doctors/,/Referenced 1 time/],
    ['SQL variable with plaintext',"SET @seed_password = 'demo-only-password';\nINSERT INTO doctors (username, password) VALUES ('doctor1', @seed_password);",/Quoted value may be plaintext/,/doctors/,/Referenced 1 time/]
  ];
  for(const [label,input,...patterns] of sqlCases){const got=await evaluate(`(()=>{document.querySelector('#sqlInput').value=${JSON.stringify(input)};document.querySelector('#analyzeSqlBtn').click();return document.querySelector('#sqlOutput').innerText})()`);for(const pattern of patterns)assert.match(got,pattern,`${label}: ${pattern}`);console.log(`PASS ${label}`);}
  async function generate(text,algorithm,iterations=100000){return evaluate(`(async()=>{const t=document.querySelector('#plainInput');t.value=${JSON.stringify(text)};document.querySelector('#generateAlgorithm').value=${JSON.stringify(algorithm)};document.querySelector('#generateAlgorithm').dispatchEvent(new Event('change'));if(${JSON.stringify(algorithm)}==='PBKDF2')document.querySelector('#pbkdfIterations').value=${iterations};document.querySelector('#generateBtn').click();while(document.querySelector('#generationStatus').textContent==='WORKING…')await new Promise(r=>setTimeout(r,10));return {hash:document.querySelector('#generatedHash').textContent,error:document.querySelector('#generateError').textContent,status:document.querySelector('#generationStatus').textContent}})()`);}
  const hello=await generate('Hello World','SHA-256');assert.equal(hello.hash,'a591a6d40bf420404a011733cfb7b190d62c65bf0bcda32b57b277d9ad9f146e');assert.equal(hello.status,'SUCCESS');console.log('PASS Hello World SHA-256');
  const repeat=await generate('Hello World','SHA-256');assert.equal(repeat.hash,hello.hash);console.log('PASS repeated SHA-256 is deterministic');
  assert.match(await verify('Hello World',hello.hash,'SHA-256'),/Match/);assert.match(await verify('Hello Wrong',hello.hash,'SHA-256'),/No Match/);console.log('PASS digest verification and mismatch');
  const malformedDigest=await evaluate(`(async()=>{document.querySelector('#verifyText').value='Hello';document.querySelector('#existingHash').value='bad';document.querySelector('#verifyAlgorithm').value='SHA-256';document.querySelector('#verifyBtn').click();while(document.querySelector('#verifyOutput').textContent==='Checking…')await new Promise(r=>setTimeout(r,10));return document.querySelector('#verifyError').textContent})()`);assert.match(malformedDigest,/64 characters/);console.log('PASS malformed digest validation');
  const med=await generate('MedConnect','MD5');assert.equal(med.hash.length,32);console.log('PASS MedConnect MD5 educational digest');
  const empty=await generate('','SHA-256');assert.equal(empty.hash,'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');console.log('PASS empty string digest');
  const unicode=await generate('Hello नमस्ते','SHA-384');assert.equal(unicode.hash.length,96);console.log('PASS Unicode SHA-384');
  const long=await generate(('line one\nline two\n').repeat(2048),'SHA-512');assert.equal(long.hash.length,128);console.log('PASS long multiline SHA-512');
  const unsupported=await evaluate(`(async()=>{const s=document.querySelector('#generateAlgorithm');s.add(new Option('unsupported test','SHA-999'));s.value='SHA-999';document.querySelector('#generateBtn').click();while(document.querySelector('#generationStatus').textContent==='WORKING…')await new Promise(r=>setTimeout(r,10));return document.querySelector('#generateError').textContent})()`);assert.match(unsupported,/Unsupported algorithm/);console.log('PASS unsupported algorithm error');
  async function verify(text,hash,algorithm){return evaluate(`(async()=>{document.querySelector('#verifyText').value=${JSON.stringify(text)};document.querySelector('#existingHash').value=${JSON.stringify(hash)};document.querySelector('#verifyAlgorithm').value=${JSON.stringify(algorithm)};document.querySelector('#verifyBtn').click();while(document.querySelector('#verifyOutput').textContent==='Checking…')await new Promise(r=>setTimeout(r,10));return document.querySelector('#verifyOutput').textContent})()`);}
  const pbkdf=await generate('candidate','PBKDF2',100000);assert.match(pbkdf.hash,/^\$pbkdf2-sha256\$100000\$/);const pbkdfVerify=await verify('candidate',pbkdf.hash,'PBKDF2');assert.match(pbkdfVerify,/Match/);const pbkdfWrong=await verify('wrong',pbkdf.hash,'PBKDF2');assert.match(pbkdfWrong,/No Match/);console.log('PASS PBKDF2 salted generation and verification');
  const badIterations=await generate('candidate','PBKDF2',99999);assert.match(badIterations.error,/100,000/);console.log('PASS PBKDF2 parameter validation');
  const bcrypt1=await generate('candidate','bcrypt');assert.match(bcrypt1.hash,/^\$2[aby]\$/);const bcrypt2=await generate('candidate','bcrypt');assert.notEqual(bcrypt1.hash,bcrypt2.hash);const bcryptVerify=await verify('candidate',bcrypt1.hash,'bcrypt');assert.match(bcryptVerify,/Match/);const bcryptWrong=await verify('wrong',bcrypt1.hash,'bcrypt');assert.match(bcryptWrong,/No Match/);console.log('PASS bcrypt random salts and candidate verification');
  const caps=await fetch(`http://127.0.0.1:${webPort}/api.php?action=capabilities`).then(r=>r.json());if(caps.argon2id){const argon=await generate('candidate','argon2id');assert.match(argon.hash,/^\$argon2id\$/);assert.match(await verify('candidate',argon.hash,'argon2id'),/Match/);assert.match(await verify('wrong',argon.hash,'argon2id'),/No Match/);console.log('PASS Argon2id local generation and verification');}else{const state=await evaluate(`document.querySelector('#generateAlgorithm option[value="argon2id"]').disabled`);assert.equal(state,true);console.log('PASS unsupported Argon2id is disabled clearly');}
  console.log('All requested generator, comparison, and prior analyzer smoke cases passed.');
}
run().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>{socket?.close();child.kill();server.kill();try{fs.rmSync(profile,{recursive:true,force:true});}catch{}});
