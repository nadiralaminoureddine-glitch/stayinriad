// Génère les versions par langue de la page d'accueil (contenu déjà écrit dans le HTML pour Google),
// avec titres, descriptions, hreflang, données structurées, robots.txt et sitemap.xml.
const {chromium}=require('playwright');const fs=require('fs');const path=require('path');
const ROOT='/home/claude/stayinriad', OUT=ROOT+'/dist', SITE='https://stayinriad.com';
const L={
 fr:{path:'/',locale:'fr_FR',title:'Riads au Maroc : réservez votre riad à Marrakech, Fès, Essaouira | StayInRiad',
     desc:'StayInRiad, la plateforme de réservation 100 % marocaine dédiée aux riads : riads de charme à Marrakech, Fès, Essaouira, Chefchaouen et dans le désert. Ouverture prochaine.'},
 en:{path:'/en/',locale:'en_GB',title:'Riads in Morocco: book a riad in Marrakech, Fez, Essaouira | StayInRiad',
     desc:'StayInRiad is a 100% Moroccan booking platform for riads only: traditional riads with courtyards, pools and rooftop terraces in Marrakech, Fez, Essaouira, Chefchaouen and the Sahara. Opening soon.'},
 es:{path:'/es/',locale:'es_ES',title:'Riads en Marruecos: reserva tu riad en Marrakech, Fez, Esauira | StayInRiad',
     desc:'StayInRiad, la plataforma de reservas 100 % marroquí dedicada solo a los riads: riads con encanto en Marrakech, Fez, Esauira, Chefchaouen y el desierto. Próxima apertura.'},
 ar:{path:'/ar/',locale:'ar_MA',title:'رياضات في المغرب: احجز رياضك في مراكش وفاس والصويرة | StayInRiad',
     desc:'StayInRiad منصة حجز مغربية 100% مخصصة للرياضات فقط: رياضات تقليدية في مراكش وفاس والصويرة وشفشاون والصحراء. الافتتاح قريبًا.'},
};
const alt=Object.entries(L).map(([k,v])=>`<link rel="alternate" hreflang="${k}" href="${SITE}${v.path}">`).join('\n')+`\n<link rel="alternate" hreflang="x-default" href="${SITE}/">`;
const ld=JSON.stringify([{"@context":"https://schema.org","@type":"Organization","name":"StayInRiad","url":SITE+"/","logo":SITE+"/apple-touch-icon.png","email":"contact@stayinriad.com","sameAs":["https://www.instagram.com/stayinriad/"]},
 {"@context":"https://schema.org","@type":"WebSite","name":"StayInRiad","url":SITE+"/","inLanguage":["fr","en","es","ar"]}]);
(async()=>{
 const src=fs.readFileSync(ROOT+'/index.html','utf8');
 fs.rmSync(OUT,{recursive:true,force:true});fs.mkdirSync(OUT,{recursive:true});
 const b=await chromium.launch();
 for(const [lang,m] of Object.entries(L)){
  const tmp=src.replace('<html lang="fr">',`<html lang="${lang}" dir="${lang==='ar'?'rtl':'ltr'}" data-page="${lang}"${lang==='fr'?' data-root="1"':''}>`);
  const tf='/tmp/build_'+lang+'.html';fs.writeFileSync(tf,tmp);
  const p=await b.newPage({userAgent:'Mozilla/5.0 (compatible; StayInRiadBuild/1.0; bot)'});
  await p.route('**/*',r=>/fonts\.(googleapis|gstatic)/.test(r.request().url())?r.abort():r.continue());
  await p.goto('file://'+tf);await p.waitForTimeout(500);
  await p.evaluate(({m,lang,alt,ld,SITE})=>{
    document.title=m.title;
    const set=(sel,attr,val)=>{let e=document.head.querySelector(sel);if(!e){e=document.createElement(sel.startsWith('link')?'link':'meta');document.head.appendChild(e);}
      const kv=sel.match(/\[(\w[\w:-]*)="([^"]+)"\]/);if(kv)e.setAttribute(kv[1],kv[2]);e.setAttribute(attr,val);};
    set('meta[name="description"]','content',m.desc);
    set('meta[property="og:title"]','content',m.title);set('meta[property="og:description"]','content',m.desc);
    set('meta[property="og:url"]','content',SITE+m.path);set('meta[property="og:locale"]','content',m.locale);
    set('meta[name="twitter:title"]','content',m.title);set('meta[name="twitter:description"]','content',m.desc);
    document.head.querySelectorAll('link[rel="canonical"],link[rel="alternate"][hreflang],script[type="application/ld+json"]').forEach(x=>x.remove());
    const c=document.createElement('link');c.rel='canonical';c.href=SITE+m.path;document.head.appendChild(c);
    document.head.insertAdjacentHTML('beforeend',alt);
    const s=document.createElement('script');s.type='application/ld+json';s.textContent=ld;document.head.appendChild(s);
    document.getElementById('langNote').innerHTML='';
  },{m,lang,alt,ld,SITE});
  let html='<!DOCTYPE html>\n'+(await p.evaluate(()=>document.documentElement.outerHTML));
  const dir=lang==='fr'?OUT:OUT+'/'+lang;fs.mkdirSync(dir,{recursive:true});fs.writeFileSync(dir+'/index.html',html);
  await p.close();
 }
 await b.close();
 fs.writeFileSync(OUT+'/robots.txt',`User-agent: *\nAllow: /\nDisallow: /app/\n\nSitemap: ${SITE}/sitemap.xml\n`);
 const urls=Object.values(L).map(v=>`  <url>\n    <loc>${SITE}${v.path}</loc>\n${Object.entries(L).map(([k,w])=>`    <xhtml:link rel="alternate" hreflang="${k}" href="${SITE}${w.path}"/>`).join('\n')}\n    <xhtml:link rel="alternate" hreflang="x-default" href="${SITE}/"/>\n    <changefreq>weekly</changefreq>\n  </url>`).join('\n');
 fs.writeFileSync(OUT+'/sitemap.xml',`<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">\n${urls}\n</urlset>\n`);
 for(const f of ['partage.jpg','apple-touch-icon.png','.htaccess'])fs.copyFileSync(ROOT+'/'+f,OUT+'/'+f);
 fs.cpSync(ROOT+'/conditions',OUT+'/conditions',{recursive:true,filter:f=>!f.endsWith('.md')});
 console.log('ok');
})();
