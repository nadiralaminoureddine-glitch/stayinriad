# Génère conditions/index.html à partir de conditions/conditions.md
import markdown, pathlib
root = pathlib.Path('/home/claude/stayinriad')
body = markdown.markdown((root/'conditions/conditions.md').read_text(encoding='utf-8'), extensions=['tables'])
html = f'''<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Conditions générales d'utilisation | StayInRiad</title>
<meta name="description" content="Conditions générales d'utilisation de stayinriad.com : réservation, paiement, annulation, séjour et données personnelles.">
<link rel="canonical" href="https://stayinriad.com/conditions/"><link rel="icon" href="/favicon.ico" sizes="48x48"><link rel="icon" href="/favicon.svg" type="image/svg+xml"><link rel="apple-touch-icon" href="/apple-touch-icon.png">
<style>
:root{{--green:#0f5a4a;--terra:#b5472b;--ink:#1f1a17;--muted:#6b625a;--line:#e3d7c5;--gold:#d9a441}}
*{{box-sizing:border-box}}body{{margin:0;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink);background:#fbf8f3;line-height:1.6}}
header{{background:var(--green);color:#fff;padding:18px 20px}}header a{{color:#fff;text-decoration:none;font-family:Georgia,serif;font-weight:700;font-size:22px}}header a span{{color:var(--gold)}}
main{{max-width:820px;margin:0 auto;padding:24px 20px 60px}}
h1{{font-family:Georgia,serif;color:var(--green);font-size:30px;line-height:1.2;margin:10px 0}}h2{{font-size:20px;margin:34px 0 8px;color:var(--green)}}
a{{color:var(--green)}}table{{border-collapse:collapse;width:100%;font-size:14px;margin:12px 0;background:#fff}}th,td{{border:1px solid var(--line);padding:8px;text-align:left;vertical-align:top}}th{{background:#f3ece0}}
.scroll{{overflow-x:auto}}footer{{text-align:center;color:var(--muted);font-size:13px;padding:20px;border-top:1px solid var(--line)}}
</style></head><body>
<header><a href="/">stay<span>in</span>riad<span>.com</span></a></header>
<main>{body.replace('<table>','<div class="scroll"><table>').replace('</table>','</table></div>')}</main>
<footer>© 2026 StayInRiad · <a href="/">Accueil</a> · <a href="mailto:contact@stayinriad.com">contact@stayinriad.com</a></footer>
</body></html>
'''
(root/'conditions/index.html').write_text(html, encoding='utf-8')
print('conditions ok')
