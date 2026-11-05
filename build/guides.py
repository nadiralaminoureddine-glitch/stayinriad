# Génère les guides de villes et la page propriétaires (FR + EN), avec données structurées.
import json, pathlib, html
ROOT = pathlib.Path('/home/claude/stayinriad')
SITE = 'https://stayinriad.com'
REG = '/app/register.php?type=riad'

CITIES = {
 'marrakech': {
  'fr': dict(name='Marrakech', title="Riad à Marrakech : bien choisir son riad dans la médina | StayInRiad",
   desc="Où dormir à Marrakech ? Notre guide des quartiers de la médina (Mouassine, Kasbah, Riad Zitoun, Bab Doukkala), la meilleure saison et nos conseils pour choisir votre riad.",
   h1="Riads à Marrakech", lead="Dormir dans un riad, c'est vivre Marrakech de l'intérieur : derrière une porte discrète de la médina, un patio planté d'orangers, une fontaine et une terrasse sur les toits, à quelques minutes à pied de la place Jemaa el-Fna.",
   areas=[("Mouassine","Au nord de Jemaa el-Fna, près des souks et de la fontaine Mouassine. Ruelles calmes, galeries d'art et Le Jardin Secret : idéal pour un premier séjour."),
          ("Riad Zitoun et la Kasbah","Au sud, près du palais Bahia, du palais El Badi et des Tombeaux saadiens. Quartier vivant, facile d'accès en taxi."),
          ("Le Mellah","L'ancien quartier juif, à côté du palais Bahia. Plus populaire, riads souvent plus abordables."),
          ("Bab Doukkala et Sidi Mimoun","Plus résidentiels, proches des portes de la médina : pratiques si vous arrivez en voiture."),
          ("Laksour","Entre Jemaa el-Fna et Mouassine : en plein centre, pour tout faire à pied.")],
   season="Le printemps (mars à mai) et l'automne (octobre-novembre) sont les plus agréables. L'été est très chaud, souvent plus de 40 °C : choisissez alors un riad avec piscine ou bassin. En hiver, les journées sont douces mais les nuits fraîches : vérifiez que la chambre est chauffée.",
   tips=["Demandez au riad de vous attendre à l'entrée de la médina : les taxis ne peuvent pas entrer dans les ruelles.","Une terrasse sur les toits vaut le détour pour le petit-déjeuner et le coucher de soleil.","La taxe de séjour se règle sur place, en plus du prix de la chambre."],
   faq=[("Quel est le meilleur quartier pour un riad à Marrakech ?","Pour un premier séjour, Mouassine et Laksour : vous êtes au cœur de la médina, près des souks et de Jemaa el-Fna, dans des ruelles plus calmes. Riad Zitoun et la Kasbah sont pratiques pour visiter les palais."),
        ("Un riad est-il accessible en voiture ?","Rarement jusqu'à la porte : la plupart des riads sont dans des ruelles piétonnes. Le riad envoie souvent quelqu'un vous chercher au parking ou à la porte de la médina la plus proche."),
        ("Quand partir à Marrakech ?","Au printemps et en automne. L'été est très chaud, l'hiver doux le jour mais frais la nuit.")]),
  'en': dict(name='Marrakech', title="Riads in Marrakech: how to choose your riad in the medina | StayInRiad",
   desc="Where to stay in Marrakech? Our guide to the medina's neighbourhoods (Mouassine, Kasbah, Riad Zitoun, Bab Doukkala), the best season and tips for choosing your riad.",
   h1="Riads in Marrakech", lead="Staying in a riad means living Marrakech from the inside: behind a discreet door in the medina, a courtyard with orange trees, a fountain and a rooftop terrace, a few minutes' walk from Jemaa el-Fna square.",
   areas=[("Mouassine","North of Jemaa el-Fna, close to the souks and the Mouassine fountain. Quiet lanes, art galleries and Le Jardin Secret: ideal for a first visit."),
          ("Riad Zitoun and the Kasbah","To the south, near Bahia Palace, El Badi Palace and the Saadian Tombs. Lively and easy to reach by taxi."),
          ("The Mellah","The old Jewish quarter, next to Bahia Palace. More local, riads are often more affordable."),
          ("Bab Doukkala and Sidi Mimoun","More residential, close to the medina gates: handy if you arrive by car."),
          ("Laksour","Between Jemaa el-Fna and Mouassine: right in the centre, everything within walking distance.")],
   season="Spring (March to May) and autumn (October–November) are the most pleasant. Summer is very hot, often above 40 °C: pick a riad with a pool or plunge pool. Winter days are mild but nights are cool: check that the room is heated.",
   tips=["Ask the riad to meet you at the edge of the medina: taxis cannot drive into the lanes.","A rooftop terrace is worth it for breakfast and sunset.","Tourist tax is paid on site, on top of the room price."],
   faq=[("What is the best area to stay in a riad in Marrakech?","For a first visit, Mouassine and Laksour: you are in the heart of the medina, near the souks and Jemaa el-Fna, in quieter lanes. Riad Zitoun and the Kasbah are handy for visiting the palaces."),
        ("Can I reach my riad by car?","Rarely to the door: most riads are on pedestrian lanes. The riad usually sends someone to meet you at the car park or the nearest medina gate."),
        ("When is the best time to visit Marrakech?","Spring and autumn. Summer is very hot; winter is mild by day but cool at night.")]),
 },
 'fes': {
  'fr': dict(name='Fès', title="Riad à Fès : où dormir dans la médina de Fès el-Bali | StayInRiad",
   desc="Où dormir à Fès ? Guide des quartiers de la médina (Talaa Kebira, Batha, Bab Boujloud, quartier des Andalous), meilleure saison et conseils pour choisir votre riad à Fès.",
   h1="Riads à Fès", lead="Fès el-Bali est l'une des plus grandes médinas du monde et un site classé par l'UNESCO. Ses riads, souvent d'anciennes demeures de familles de marchands, cachent des zelliges, des plâtres sculptés et du cèdre parmi les plus beaux du Maroc.",
   areas=[("Bab Boujloud","À l'entrée de la médina, près de la porte bleue. Accès facile en taxi et départ des deux grandes rues, Talaa Kebira et Talaa Seghira."),
          ("Talaa Kebira","La grande rue qui descend vers la Quaraouiyine : au cœur des souks, des medersas et des artisans."),
          ("Batha","Près du musée Batha et de Bab Boujloud : calme et bien desservi, idéal pour arriver et repartir facilement."),
          ("Quartier des Andalous","De l'autre côté de l'oued, plus tranquille et moins touristique, près de la mosquée des Andalous."),
          ("Fès Jdid","La « nouvelle » Fès, près du palais royal et du Mellah : plus aérée, un bon point de départ.")],
   season="Le printemps et l'automne sont parfaits. L'été est chaud et l'hiver froid, surtout la nuit : en hiver, vérifiez que le riad est chauffé.",
   tips=["La médina de Fès est un vrai labyrinthe : faites-vous guider par le riad le premier jour.","Choisissez un riad près d'une porte (Bab Boujloud, Batha) si vous avez de grosses valises.","Montez sur une terrasse au coucher du soleil : la vue sur la médina est unique."],
   faq=[("Quel quartier choisir pour un riad à Fès ?","Bab Boujloud et Batha pour un accès facile ; Talaa Kebira pour être au cœur des souks ; le quartier des Andalous pour plus de calme."),
        ("Peut-on se perdre dans la médina de Fès ?","Oui, c'est fréquent : les ruelles sont très nombreuses. La plupart des riads proposent de venir vous chercher et peuvent vous conseiller un guide officiel."),
        ("Quand visiter Fès ?","Au printemps et en automne. L'hiver est froid la nuit, l'été chaud.")]),
  'en': dict(name='Fez', title="Riads in Fez: where to stay in the Fes el-Bali medina | StayInRiad",
   desc="Where to stay in Fez? Guide to the medina's neighbourhoods (Talaa Kebira, Batha, Bab Boujloud, Andalusian quarter), the best season and tips for choosing your riad in Fez.",
   h1="Riads in Fez", lead="Fes el-Bali is one of the largest medinas in the world and a UNESCO World Heritage site. Its riads, often former merchant family homes, hide some of the finest zellige tiles, carved plaster and cedar wood in Morocco.",
   areas=[("Bab Boujloud","At the entrance of the medina, near the Blue Gate. Easy taxi access and the start of the two main streets, Talaa Kebira and Talaa Seghira."),
          ("Talaa Kebira","The main street running down to the Qarawiyyin: in the heart of the souks, madrasas and craftsmen."),
          ("Batha","Near the Batha Museum and Bab Boujloud: quiet and well connected, ideal for easy arrivals and departures."),
          ("Andalusian quarter","Across the river, quieter and less touristy, near the Andalusian Mosque."),
          ("Fes Jdid","The 'new' Fez, near the Royal Palace and the Mellah: more open, a good base.")],
   season="Spring and autumn are perfect. Summer is hot and winter cold, especially at night: in winter, check that the riad is heated.",
   tips=["The Fez medina is a real maze: let the riad guide you on your first day.","Pick a riad near a gate (Bab Boujloud, Batha) if you have big suitcases.","Go up to a rooftop at sunset: the view over the medina is unique."],
   faq=[("Which area should I choose for a riad in Fez?","Bab Boujloud and Batha for easy access; Talaa Kebira to be in the heart of the souks; the Andalusian quarter for more peace and quiet."),
        ("Can you get lost in the Fez medina?","Yes, it happens a lot: there are countless lanes. Most riads offer to pick you up and can recommend an official guide."),
        ("When should I visit Fez?","Spring and autumn. Winter nights are cold and summer is hot.")]),
 },
 'essaouira': {
  'fr': dict(name='Essaouira', title="Riad à Essaouira : dormir dans la médina face à l'océan | StayInRiad",
   desc="Où dormir à Essaouira ? Guide de la médina classée UNESCO (Skala, Mellah, place Moulay Hassan), le vent et la meilleure saison, et nos conseils pour choisir votre riad.",
   h1="Riads à Essaouira", lead="Remparts face à l'Atlantique, médina blanche aux volets bleus classée par l'UNESCO, port de pêche animé : Essaouira est la ville la plus détendue du Maroc, et ses riads ont souvent une terrasse avec vue sur l'océan.",
   areas=[("Autour de la place Moulay Hassan","Entre le port et la médina : cafés, restaurants de poisson, tout à pied."),
          ("La Skala","Le long des remparts et des canons : riads avec vue sur l'océan et les couchers de soleil."),
          ("Le Mellah","Au nord de la médina, plus authentique et souvent plus abordable."),
          ("Près de Bab Sbaa et Bab Doukkala","Près des portes : pratique pour la plage, le parking et la gare routière.")],
   season="Le climat est doux toute l'année, rarement très chaud. L'alizé souffle fort, surtout au printemps et en été : parfait pour le kitesurf, moins pour bronzer. Prévoyez une petite laine le soir.",
   tips=["Demandez une chambre ou une terrasse avec vue sur l'océan.","La médina est petite : tout se fait à pied.","Le vent est fréquent : une terrasse abritée est un vrai plus."],
   faq=[("Où dormir à Essaouira ?","Dans la médina, près de la place Moulay Hassan pour tout faire à pied, ou le long de la Skala pour la vue sur l'océan."),
        ("Fait-il chaud à Essaouira en été ?","Rarement : la ville est rafraîchie par l'océan et le vent. C'est une bonne étape quand Marrakech est très chaude."),
        ("Essaouira est-elle loin de Marrakech ?","Environ 3 heures de route ou de bus.")]),
  'en': dict(name='Essaouira', title="Riads in Essaouira: stay in the medina by the ocean | StayInRiad",
   desc="Where to stay in Essaouira? Guide to the UNESCO-listed medina (Skala, Mellah, Moulay Hassan square), the wind and the best season, and tips for choosing your riad.",
   h1="Riads in Essaouira", lead="Ramparts facing the Atlantic, a white medina with blue shutters listed by UNESCO, a busy fishing port: Essaouira is Morocco's most relaxed city, and its riads often have a rooftop with an ocean view.",
   areas=[("Around Moulay Hassan square","Between the port and the medina: cafés, fish restaurants, everything within walking distance."),
          ("The Skala","Along the ramparts and cannons: riads with ocean views and sunsets."),
          ("The Mellah","In the north of the medina, more authentic and often more affordable."),
          ("Near Bab Sbaa and Bab Doukkala","Near the gates: handy for the beach, parking and the bus station.")],
   season="The climate is mild all year round and rarely very hot. The trade wind blows hard, especially in spring and summer: great for kitesurfing, less so for sunbathing. Bring a jumper for the evenings.",
   tips=["Ask for a room or terrace with an ocean view.","The medina is small: everything is within walking distance.","It is often windy: a sheltered terrace is a real plus."],
   faq=[("Where should I stay in Essaouira?","In the medina, near Moulay Hassan square to walk everywhere, or along the Skala for the ocean view."),
        ("Is Essaouira hot in summer?","Rarely: the ocean and the wind keep it cool. It is a good stop when Marrakech is very hot."),
        ("How far is Essaouira from Marrakech?","About 3 hours by road or bus.")]),
 },
 'chefchaouen': {
  'fr': dict(name='Chefchaouen', title="Riad à Chefchaouen : dormir dans la ville bleue | StayInRiad",
   desc="Où dormir à Chefchaouen ? Guide de la médina bleue (Outa el Hammam, Ras el Ma, Bab el Ain), la meilleure saison dans le Rif et nos conseils pour choisir votre riad ou votre dar.",
   h1="Riads à Chefchaouen", lead="Nichée dans les montagnes du Rif, Chefchaouen est célèbre pour ses ruelles peintes en bleu. Ses maisons d'hôtes, souvent appelées « dar », sont plus petites qu'à Marrakech, avec des terrasses qui donnent sur la montagne.",
   areas=[("Place Outa el Hammam","Le cœur de la médina, face à la kasbah : animé, tout près des restaurants."),
          ("Ras el Ma","En haut de la médina, près de la source et du lavoir : plus calme, vue sur la montagne."),
          ("Bab el Ain","L'entrée principale de la médina : pratique pour arriver avec des bagages.")],
   season="Le printemps et l'automne sont idéaux. L'été est agréable en montagne. L'hiver est froid et pluvieux : choisissez une chambre chauffée.",
   tips=["La médina est en pente : demandez un riad proche de Bab el Ain si vous avez des valises.","Montez à la mosquée espagnole au coucher du soleil pour la vue sur la ville bleue.","Pensez à réserver tôt les week-ends et pendant les vacances : la ville est petite."],
   faq=[("Quel quartier choisir à Chefchaouen ?","Près de la place Outa el Hammam pour être au centre, ou vers Ras el Ma pour le calme et la vue."),
        ("Comment aller à Chefchaouen ?","En bus ou en taxi depuis Tanger, Tétouan ou Fès. Il n'y a pas de gare ni d'aéroport sur place."),
        ("Quand visiter Chefchaouen ?","Au printemps et en automne. L'hiver est froid et pluvieux dans le Rif.")]),
  'en': dict(name='Chefchaouen', title="Riads in Chefchaouen: stay in the blue city | StayInRiad",
   desc="Where to stay in Chefchaouen? Guide to the blue medina (Outa el Hammam, Ras el Ma, Bab el Ain), the best season in the Rif mountains and tips for choosing your riad or dar.",
   h1="Riads in Chefchaouen", lead="Tucked into the Rif mountains, Chefchaouen is famous for its blue-painted lanes. Its guesthouses, often called 'dar', are smaller than in Marrakech, with terraces looking out over the mountains.",
   areas=[("Outa el Hammam square","The heart of the medina, facing the kasbah: lively, close to the restaurants."),
          ("Ras el Ma","At the top of the medina, near the spring and wash house: quieter, with mountain views."),
          ("Bab el Ain","The main gate of the medina: handy when arriving with luggage.")],
   season="Spring and autumn are ideal. Summer is pleasant in the mountains. Winter is cold and rainy: choose a heated room.",
   tips=["The medina is on a slope: ask for a riad near Bab el Ain if you have suitcases.","Walk up to the Spanish Mosque at sunset for the view over the blue city.","Book early for weekends and holidays: the town is small."],
   faq=[("Which area should I stay in in Chefchaouen?","Near Outa el Hammam square to be central, or towards Ras el Ma for peace and views."),
        ("How do I get to Chefchaouen?","By bus or taxi from Tangier, Tetouan or Fez. There is no train station or airport in town."),
        ("When should I visit Chefchaouen?","Spring and autumn. Winter is cold and rainy in the Rif.")]),
 },
}

L = {
 'fr': dict(home='/', guides='Guides des villes', areas='Dans quel quartier choisir son riad ?', season='Quand venir ?', tips='Nos conseils', faq='Questions fréquentes',
            soon='Les réservations ouvrent bientôt sur StayInRiad, la plateforme 100 % marocaine dédiée uniquement aux riads.', notify='Me prévenir de l\'ouverture',
            owner_h='Vous avez un riad à {city} ?', owner_p='Inscrivez-le gratuitement dès maintenant : il sera en ligne dès l\'ouverture des réservations. Vous choisissez votre commission : 15, 18 ou 20 %.', owner_b='Inscrire mon riad gratuitement',
            other='Autres villes', crumb_home='Accueil', lang_other='English', path='/{slug}/'),
 'en': dict(home='/en/', guides='City guides', areas='Which area should you choose?', season='When to go', tips='Our tips', faq='Frequently asked questions',
            soon='Bookings open soon on StayInRiad, the 100% Moroccan platform dedicated only to riads.', notify='Notify me at launch',
            owner_h='Do you own a riad in {city}?', owner_p='List it for free now: it will be online as soon as bookings open. You choose your commission (15–20%).', owner_b='List my riad for free',
            other='Other cities', crumb_home='Home', lang_other='Français', path='/en/{slug}/'),
}
SLUG = {'fr': 'riads-{c}', 'en': 'riads-{c}'}

CSS = """:root{--g:#0f5a4a;--gd:#0a3f34;--gold:#d9a441;--terra:#b5472b;--ink:#1f1a17;--muted:#6b625a;--line:#e3d7c5;--sand:#f7efe3}
*{box-sizing:border-box}body{margin:0;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink);background:#fbf8f3;line-height:1.6}
a{color:var(--g)}header{background:var(--g);color:#fff}.hw{max-width:860px;margin:0 auto;padding:14px 20px;display:flex;justify-content:space-between;align-items:center;gap:12px}
.logo{display:flex;align-items:center;gap:10px;color:#fff;text-decoration:none;font-family:Fraunces,Georgia,serif;font-weight:700;font-size:22px}.logo b{color:var(--gold);font-weight:700}
.hl{display:flex;gap:8px;align-items:center}.hl a{color:#fff;font-size:14px}.hl a.btn{background:#fff;color:var(--g);text-decoration:none;font-weight:700;border-radius:8px;padding:8px 12px}
.hero{background:var(--g);color:#fff;padding:10px 0 34px}.hero .w{max-width:860px;margin:0 auto;padding:0 20px}
.crumb{font-size:13px;opacity:.85}.crumb a{color:#fff}h1{font-family:Fraunces,Georgia,serif;font-size:40px;line-height:1.1;margin:12px 0}
.lead{font-size:18px;opacity:.95;max-width:720px}main{max-width:860px;margin:0 auto;padding:10px 20px 40px}
h2{font-family:Fraunces,Georgia,serif;font-size:26px;margin:34px 0 10px;color:var(--ink)}
.areas{display:grid;gap:10px}.area{background:#fff;border:1px solid var(--line);border-radius:12px;padding:14px 16px}.area b{display:block;color:var(--g);margin-bottom:2px}
ul.tips{padding-left:20px}ul.tips li{margin:6px 0}
.cta{background:var(--g);color:#fff;border-radius:16px;padding:22px;margin:34px 0}.cta h2{color:#fff;margin-top:0}.cta p{opacity:.95}
.btn{display:inline-block;background:var(--terra);color:#fff;text-decoration:none;font-weight:700;border-radius:10px;padding:12px 18px}.btn.light{background:#fff;color:var(--g)}
details{background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px 16px;margin:8px 0}summary{font-weight:700;cursor:pointer}
.others{display:flex;flex-wrap:wrap;gap:8px}.others a{background:#fff;border:1px solid var(--line);border-radius:99px;padding:8px 14px;text-decoration:none;font-weight:600}
.steps{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.steps div{background:#fff;border:1px solid var(--line);border-radius:12px;padding:14px}.steps b{display:block;color:var(--g)}
footer{border-top:1px solid var(--line);text-align:center;color:var(--muted);font-size:13px;padding:22px}
@media(max-width:640px){h1{font-size:32px}.steps{grid-template-columns:1fr}.hl a:not(.btn){display:none}}"""

STAR = '<svg width="30" height="30" viewBox="0 0 40 40" aria-hidden="true"><rect x="8" y="8" width="24" height="24" fill="#d9a441"/><rect x="8" y="8" width="24" height="24" fill="#d9a441" transform="rotate(45 20 20)"/><rect x="13" y="13" width="14" height="14" fill="#0f5a4a"/><rect x="13" y="13" width="14" height="14" fill="#0f5a4a" transform="rotate(45 20 20)"/><circle cx="20" cy="20" r="3" fill="#d9a441"/></svg>'

def page(lang, slug_fr, slug_en, title, desc, body, ld, crumb):
    html = _page(lang, slug_fr, slug_en, title, desc, body, ld, crumb)
    return html.replace(REG + '"', REG + '&amp;lang=en"') if lang == 'en' else html

def _page(lang, slug_fr, slug_en, title, desc, body, ld, crumb):
    e = html.escape
    if lang == 'fr':
        import re as _re
        body = _re.sub(r' ([?!:;»])', '\u00a0\\1', body).replace('« ', '«\u00a0')
    url = SITE + (f'/{slug_fr}/' if lang == 'fr' else f'/en/{slug_en}/')
    alt = f'<link rel="alternate" hreflang="fr" href="{SITE}/{slug_fr}/"><link rel="alternate" hreflang="en" href="{SITE}/en/{slug_en}/"><link rel="alternate" hreflang="x-default" href="{SITE}/{slug_fr}/">'
    other = f'/en/{slug_en}/' if lang == 'fr' else f'/{slug_fr}/'
    t = L[lang]
    bc = {"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[{"@type":"ListItem","position":1,"name":t['crumb_home'],"item":SITE+t['home']},{"@type":"ListItem","position":2,"name":crumb,"item":url}]}
    return f'''<!DOCTYPE html>
<html lang="{lang}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{e(title)}</title><meta name="description" content="{e(desc)}"><link rel="canonical" href="{url}">{alt}
<meta property="og:type" content="article"><meta property="og:title" content="{e(title)}"><meta property="og:description" content="{e(desc)}"><meta property="og:url" content="{url}"><meta property="og:image" content="{SITE}/partage.jpg"><meta property="og:locale" content="{'fr_FR' if lang=='fr' else 'en_GB'}"><meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="/favicon.ico" sizes="48x48"><link rel="icon" href="/favicon.svg" type="image/svg+xml"><link rel="apple-touch-icon" href="/apple-touch-icon.png"><meta name="theme-color" content="#0f5a4a">
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700&amp;family=Inter:wght@400;600;700&amp;display=swap" rel="stylesheet">
<style>{CSS}</style>
<script type="application/ld+json">{json.dumps([bc]+ld, ensure_ascii=False)}</script></head><body>
<header><div class="hw"><a class="logo" href="{t['home']}">{STAR}<span>stay<b>in</b>riad<b>.com</b></span></a><div class="hl"><a href="{other}" hreflang="{'en' if lang=='fr' else 'fr'}">{t['lang_other']}</a><a class="btn" href="{REG}">{'Inscrire mon riad' if lang=='fr' else 'List my riad'}</a></div></div></header>
{body}
<footer>© 2026 StayInRiad · <a href="{t['home']}">{t['crumb_home']}</a> · <a href="/conditions/">{'Conditions d’utilisation' if lang=='fr' else 'Terms of use (in French)'}</a> · <a href="mailto:contact@stayinriad.com">contact@stayinriad.com</a></footer>
</body></html>'''

out = []
for key, langs in CITIES.items():
    for lang, d in langs.items():
        t = L[lang]; e = html.escape
        others = ''.join(f'<a href="{(t["path"]).format(slug="riads-"+k)}">{e(CITIES[k][lang]["name"])}</a>' for k in CITIES if k != key)
        body = f'''<section class="hero"><div class="w"><div class="crumb"><a href="{t['home']}">{t['crumb_home']}</a> › {e(d['h1'])}</div><h1>{e(d['h1'])}</h1><p class="lead">{e(d['lead'])}</p><a class="btn light" href="{t['home']}#prevenir">{t['notify']}</a></div></section>
<main><h2>{t['areas']}</h2><div class="areas">{''.join(f'<div class="area"><b>{e(a)}</b>{e(b)}</div>' for a,b in d['areas'])}</div>
<h2>{t['season']}</h2><p>{e(d['season'])}</p>
<h2>{t['tips']}</h2><ul class="tips">{''.join(f'<li>{e(x)}</li>' for x in d['tips'])}</ul>
<p>{t['soon']}</p>
<div class="cta"><h2>{e(t['owner_h'].format(city=d['name']))}</h2><p>{e(t['owner_p'])}</p><a class="btn" href="{REG}">{t['owner_b']}</a></div>
<h2>{t['faq']}</h2>{''.join(f'<details><summary>{e(q)}</summary><p>{e(a)}</p></details>' for q,a in d['faq'])}
<h2>{t['other']}</h2><div class="others">{others}</div></main>'''
        faq = {"@context":"https://schema.org","@type":"FAQPage","mainEntity":[{"@type":"Question","name":q,"acceptedAnswer":{"@type":"Answer","text":a}} for q,a in d['faq']]}
        html_ = page(lang, 'riads-'+key, 'riads-'+key, d['title'], d['desc'], body, [faq], d['h1'])
        p = ROOT/'guides'/(('' if lang=='fr' else 'en/')+'riads-'+key)/'index.html'
        p.parent.mkdir(parents=True, exist_ok=True); p.write_text(html_, encoding='utf-8'); out.append(str(p))

# Page propriétaires
OWN = {
 'fr': dict(slug='inscrire-mon-riad', title="Inscrire son riad sur une plateforme de réservation marocaine | StayInRiad",
   desc="Propriétaire de riad ? Inscrivez votre riad gratuitement sur StayInRiad : commission au choix : 15, 18 ou 20 %, virement chaque lundi en dirhams, synchronisation Booking et Airbnb, fiche de police en ligne.",
   h1="Inscrivez votre riad sur StayInRiad", lead="La plateforme de réservation 100 % marocaine, uniquement pour les riads. Inscription gratuite, avant le lancement : votre riad sera en ligne dès l'ouverture des réservations.",
   why="Pourquoi rejoindre StayInRiad ?",
   items=[("Une commission que vous choisissez","15 % (visibilité normale), 18 % (2e page) ou 20 % (première page), calculée uniquement sur le prix des nuits."),
          ("Un virement chaque lundi, en dirhams","Pour les séjours terminés la semaine précédente, sur le compte de votre société."),
          ("Synchronisé avec Booking et Airbnb","Vos calendriers restent à jour : une chambre réservée ailleurs se ferme chez nous, et inversement."),
          ("Fiche de police en ligne","Le voyageur la remplit avant d'arriver, vous n'avez plus qu'à l'imprimer."),
          ("Uniquement des riads","Vos clients cherchent un riad, pas un hôtel de chaîne : vous n'êtes pas noyé parmi des milliers d'hébergements."),
          ("Une équipe au Maroc","Nous remplissons la fiche avec vous si besoin, par téléphone ou WhatsApp.")],
   how="Comment ça marche ?", steps=[("1. Créez votre fiche","5 minutes : riad, chambres, prix, photos."),("2. Nous vérifions","Société, RIB et photos, en général sous 24 h."),("3. En ligne","Dès l'ouverture des réservations.")],
   faq=[("Combien coûte l'inscription ?","Rien. L'inscription est gratuite et sans engagement. Vous ne payez qu'une commission (15, 18 ou 20 %, à votre choix) sur les réservations reçues."),
        ("Puis-je rester sur Booking et Airbnb ?","Oui. Vous reliez vos calendriers et StayInRiad les met à jour automatiquement pour éviter les doubles réservations."),
        ("Quand suis-je payé ?","Chaque lundi, par virement en dirhams, pour les séjours terminés la semaine précédente."),
        ("Quels documents faut-il ?","Les informations de votre société (raison sociale, ICE, registre de commerce, autorisation d'exploitation touristique) et un RIB au nom de la société.")],
   cta="Inscrire mon riad gratuitement", video='/videos/tuto-fr.mp4', poster='/videos/tuto-fr.jpg', vh="Le tutoriel en 2 minutes"),
 'en': dict(slug='list-your-riad', title="List your riad on a Moroccan booking platform | StayInRiad",
   desc="Riad owner? List your riad for free on StayInRiad: choose your commission (15–20%), weekly payouts in dirhams every Monday, Booking and Airbnb calendar sync, online police forms.",
   h1="List your riad on StayInRiad", lead="The 100% Moroccan booking platform for riads only. Free sign-up ahead of launch: your riad will be online as soon as bookings open.",
   why="Why join StayInRiad?",
   items=[("A commission you choose","15% (standard visibility), 18% (page 2) or 20% (first page), charged on room nights only."),
          ("A payout every Monday, in dirhams","For stays that ended the previous week, to your company's bank account."),
          ("Synced with Booking and Airbnb","Your calendars stay up to date: a room booked elsewhere closes on our site, and vice versa."),
          ("Online police forms","Guests fill them in before arrival; you just print them."),
          ("Riads only","Your guests are looking for a riad, not a chain hotel: you are not lost among thousands of listings."),
          ("A team in Morocco","We can fill in your listing with you by phone or WhatsApp.")],
   how="How it works", steps=[("1. Create your listing","5 minutes: riad, rooms, prices, photos."),("2. We verify","Company, bank details and photos, usually within 24 h."),("3. Go live","As soon as bookings open.")],
   faq=[("How much does it cost to sign up?","Nothing. Sign-up is free with no commitment. You only pay a commission (15–20%, your choice) on bookings you receive."),
        ("Can I stay on Booking and Airbnb?","Yes. You link your calendars and StayInRiad keeps them in sync automatically to avoid double bookings."),
        ("When do I get paid?","Every Monday, by bank transfer in dirhams, for stays that ended the previous week."),
        ("What documents do I need?","Your company details (company name, ICE, trade register, tourism operating licence) and a bank account (RIB) in the company's name.")],
   cta="List my riad for free", video='/videos/tuto-en.mp4', poster='/videos/tuto-en.jpg', vh="The 2-minute tutorial"),
}
for lang, d in OWN.items():
    t = L[lang]; e = html.escape
    body = f'''<section class="hero"><div class="w"><div class="crumb"><a href="{t['home']}">{t['crumb_home']}</a> › {e(d['h1'])}</div><h1>{e(d['h1'])}</h1><p class="lead">{e(d['lead'])}</p><a class="btn" href="{REG}">{d['cta']}</a></div></section>
<main><h2>{d['why']}</h2><div class="areas">{''.join(f'<div class="area"><b>{e(a)}</b>{e(b)}</div>' for a,b in d['items'])}</div>
<h2>{d['how']}</h2><div class="steps">{''.join(f'<div><b>{e(a)}</b>{e(b)}</div>' for a,b in d['steps'])}</div>
<h2>{d['vh']}</h2><video controls playsinline preload="none" poster="{d['poster']}" style="width:100%;max-width:320px;aspect-ratio:9/16;border-radius:14px;background:#0a3f34;display:block"><source src="{d['video']}" type="video/mp4"></video>
<div class="cta"><h2>{e(d['cta'])}</h2><p>{e(t['owner_p'])}</p><a class="btn" href="{REG}">{d['cta']}</a></div>
<h2>{t['faq']}</h2>{''.join(f'<details><summary>{e(q)}</summary><p>{e(a)}</p></details>' for q,a in d['faq'])}
<h2>{t['guides']}</h2><div class="others">{''.join(f'<a href="{t["path"].format(slug="riads-"+k)}">{e(CITIES[k][lang]["h1"])}</a>' for k in CITIES)}</div></main>'''
    faq = {"@context":"https://schema.org","@type":"FAQPage","mainEntity":[{"@type":"Question","name":q,"acceptedAnswer":{"@type":"Answer","text":a}} for q,a in d['faq']]}
    html_ = page(lang, OWN['fr']['slug'], OWN['en']['slug'], d['title'], d['desc'], body, [faq], d['h1'])
    p = ROOT/'guides'/(('' if lang=='fr' else 'en/')+d['slug'])/'index.html'
    p.parent.mkdir(parents=True, exist_ok=True); p.write_text(html_, encoding='utf-8'); out.append(str(p))

# liste des URL pour le plan du site
pairs = [(f'/riads-{k}/', f'/en/riads-{k}/') for k in CITIES] + [(f"/{OWN['fr']['slug']}/", f"/en/{OWN['en']['slug']}/")]
(ROOT/'guides'/'pairs.json').write_text(json.dumps(pairs), encoding='utf-8')
print(len(out), 'pages')
