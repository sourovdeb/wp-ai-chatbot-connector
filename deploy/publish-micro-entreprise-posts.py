#!/usr/bin/env python3
"""Publish French + English micro-entreprise blog posts to sourovdeb.com."""
import json
import re
import urllib.request
import urllib.error

API_URL = "https://sourovdeb.com/wp-json/sourov/v1/ai-post"
API_KEY = "0767044896thevenet_"


def md_to_html(md: str) -> str:
    """Basic markdown to HTML for WordPress."""
    lines = md.strip().split("\n")
    html_parts = []
    in_ul = False
    in_table = False
    table_rows = []

    def close_ul():
        nonlocal in_ul
        if in_ul:
            html_parts.append("</ul>")
            in_ul = False

    def close_table():
        nonlocal in_table, table_rows
        if in_table and table_rows:
            html_parts.append("<table>")
            for i, row in enumerate(table_rows):
                tag = "th" if i == 0 else "td"
                html_parts.append("<tr>" + "".join(f"<{tag}>{c}</{tag}>" for c in row) + "</tr>")
            html_parts.append("</table>")
            in_table = False
            table_rows = []

    for line in lines:
        stripped = line.strip()
        if not stripped:
            close_ul()
            close_table()
            continue
        if stripped.startswith("|") and stripped.endswith("|"):
            close_ul()
            cells = [c.strip() for c in stripped.strip("|").split("|")]
            if all(re.match(r"^[-:]+$", c) for c in cells):
                continue
            if not in_table:
                in_table = True
                table_rows = []
            table_rows.append(cells)
            continue
        close_table()
        if stripped.startswith("## "):
            close_ul()
            html_parts.append(f"<h2>{inline(stripped[3:])}</h2>")
        elif stripped.startswith("# "):
            close_ul()
            html_parts.append(f"<h1>{inline(stripped[2:])}</h1>")
        elif stripped.startswith("- "):
            if not in_ul:
                html_parts.append("<ul>")
                in_ul = True
            html_parts.append(f"<li>{inline(stripped[2:])}</li>")
        elif stripped == "---":
            close_ul()
            html_parts.append("<hr>")
        else:
            close_ul()
            html_parts.append(f"<p>{inline(stripped)}</p>")

    close_ul()
    close_table()
    return "\n".join(html_parts)


def inline(text: str) -> str:
    text = re.sub(r"\[([^\]]+)\]\(([^)]+)\)", r'<a href="\2">\1</a>', text)
    text = re.sub(r"\*\*([^*]+)\*\*", r"<strong>\1</strong>", text)
    return text


def publish_post(payload: dict) -> dict:
    data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(
        API_URL,
        data=data,
        method="POST",
        headers={
            "X-Sourov-Key": API_KEY,
            "Content-Type": "application/json",
        },
    )
    with urllib.request.urlopen(req, timeout=120) as resp:
        return json.loads(resp.read().decode())


FR_MD = r"""# Comment créer sa micro-entreprise de prof d'anglais indépendant en France (guide étape par étape 2026)

**Publié le 24 juin 2026** • Par Sourov Deb

Bonjour,

Je m'appelle Sourov. Je vis à La Réunion et je viens de créer ma micro-entreprise pour donner des cours particuliers d'anglais et de la formation linguistique (IELTS, anglais professionnel, aviation, médical, business…).

J'étais un peu stressé au début parce que les démarches peuvent sembler compliquées. J'ai donc tout documenté de façon très précise pour que d'autres personnes dans la même situation puissent s'y retrouver facilement, sans avoir peur.

Si vous avez peur de vous lancer seul(e), cet article est fait pour vous.

## Étape 1 : Se préparer avant de commencer (15-30 min)

Rassemblez ces documents :
- Pièce d'identité (passeport ou CNI)
- Justificatif de domicile récent (facture EDF, internet…)
- Une idée claire de votre activité

**Conseil** : Créez un dossier sur votre ordinateur. Ça enlève énormément de stress.

## Étape 2 : Lancer la formalité sur le Guichet Unique

1. Allez sur [procedures.inpi.fr](https://procedures.inpi.fr)
2. Connectez-vous avec **FranceConnect**
3. Cliquez sur **Créer une entreprise** → **Entrepreneur individuel** → **Oui** pour micro-entrepreneur

## Étape 3 : Remplir les informations personnelles

Remplissez tous les champs marqués d'une étoile (*). Les plus importants :
- Genre, nom de naissance, date de naissance
- Adresse exacte (pour la domiciliation)
- Téléphone au format international

**Piège fréquent** : Vérifiez bien votre adresse. Une erreur peut retarder tout le dossier.

## Étape 4 : Déclarer votre activité (l'étape clé)

Cliquez sur **Ajouter une activité** et indiquez :

- **Description détaillée** : Cours particuliers d'anglais, formation linguistique, soutien scolaire, préparation IELTS/TOEIC, enseignement professionnel…
- **Code APE** : **85.59B – Autres enseignements**

C'est le bon code pour les professeurs et formateurs linguistiques indépendants.

## Étape 5 : Domiciliation

J'ai choisi de domicilier mon entreprise à mon domicile personnel.
**Important** : Votre adresse deviendra publique dans les registres officiels (RNE). C'est normal.

## Étape 6 : Joindre les pièces justificatives

Vous devez fournir :
- Pièce d'identité (PDF)
- Justificatif de domicile récent (PDF)

Formats acceptés : PDF uniquement, max 10 Mo.

## Étape 7 : Vérifier et signer

Relisez bien le récapitulatif (surtout l'activité et l'adresse), puis signez électroniquement.

## Après le dépôt : Ce qui se passe vraiment

Voici le parcours de ma formalité (J00255063042) :
1. Reçu par le Guichet Unique
2. Signature électronique
3. Transmission à l'INSEE + URSSAF
4. Réception du **SIRET** (généralement 8 à 15 jours)

## Ce que je recommande de faire tout de suite

- Notez votre numéro de formalité
- Préparez un modèle de facture simple (vous pouvez déjà facturer)
- Si un client demande le SIRET : dites-lui que c'est en cours d'attribution et donnez-lui votre numéro de dossier

## Ce qu'il faut surveiller (points d'attention)

- **Adresse** : Vérifiez-la plusieurs fois
- **Code APE** : 85.59B est le bon pour l'enseignement indépendant
- **Versement libératoire** : Vous pouvez le laisser sur **Non** au début
- **Preuve d'identité et de domicile** : Utilisez des fichiers clairs et récents

## Prochaines étapes (après réception du SIRET)

- Créer le compte URSSAF
- Déclarer la création à France Travail
- Commencer à facturer proprement

---

Vous n'êtes pas seul(e). Les démarches sont tout à fait accessibles quand on avance étape par étape.

Si cet article vous a aidé, n'hésitez pas à le partager. Je continue à documenter tout le parcours (réception du SIRET, première facturation, déclaration URSSAF, etc.).

**Article suivant** : J'ai reçu mon SIRET – Que faire dans les 48 premières heures ?

À très vite,
**Sourov**"""

EN_MD = r"""# How to Create Your Micro-Entreprise as an Independent English Teacher in France (Step-by-Step Guide 2026)

**Published: 24 June 2026** • By Sourov Deb

Hello,

My name is Sourov. I live in La Réunion and I have just created my micro-entreprise to offer private English lessons and language training (IELTS preparation, professional English for aviation, medical, hospitality, and business).

I felt a bit overwhelmed at the beginning because administrative procedures in France can seem complicated, especially if you're not used to them. So I decided to document the entire process in detail so that other people in the same situation can follow it without fear.

If you're feeling anxious about doing this alone, this guide is for you. I'll walk you through **exactly what I did**, step by step, including the small pitfalls to watch out for.

## Step 1: Prepare Before You Start (15–30 minutes)

Before opening the official portal, gather these documents:

- Valid ID (passport or French ID card)
- Recent proof of address (EDF, internet, or water bill)
- A clear idea of your activity

**Tip:** Create a dedicated folder on your computer. This simple step removes a lot of stress.

## Step 2: Start the Process on the Guichet Unique (INPI)

1. Go to [procedures.inpi.fr](https://procedures.inpi.fr)
2. Log in using **FranceConnect** (the easiest method)
3. Click **"Create a business"** → **"Entrepreneur individuel"** → Select **"Yes"** for micro-entrepreneur status

## Step 3: Fill in Your Personal Information

Complete all fields marked with an asterisk (*). Pay special attention to:

- Gender, birth name, date of birth, and nationality
- Your exact address (this will be used for domiciliation)
- Phone number in international format

**Common mistake to avoid:** Double-check your address. Even a small error can delay your entire file.

## Step 4: Declare Your Activity (The Most Important Step)

Click **"Add an activity"** and enter:

- **Detailed description**: Private English lessons, language training, exam preparation (IELTS/TOEIC), professional English courses (aviation, medical, hospitality, business). Liberal activity delivered online and in-person in South Réunion.
- **APE Code**: **85.59B – Autres enseignements**

This is the correct code for independent language teachers and trainers.

## Step 5: Choose Domiciliation

I chose to register my business at my home address (this is fully allowed for micro-entrepreneurs).
**Important note**: Your home address will become public in the official registers (RNE). This is normal and required by law.

## Step 6: Upload Supporting Documents

You must upload:

- Proof of identity (passport or ID card – recto/verso)
- Recent proof of address

**Requirements**: PDF format only, maximum 10 MB per file.

## Step 7: Review the Summary and Sign

Carefully review the final summary, especially:
- Your activity description and APE code
- Your address
- Fiscal options

Once everything looks correct, complete the electronic signature.

## What Happens After Submission (Real Timeline)

Here's what happened with my formalité (reference: **J00255063042**):

1. Received by the Guichet Unique
2. Electronic signature
3. Sent to INSEE + URSSAF
4. **SIRET** expected within 8–15 days

## What You Should Do Immediately

- Save your formalité number (e.g. J00255063042)
- Prepare a simple invoice template (you can already start issuing invoices)
- If a client asks for your SIRET: Explain that it is being processed and share your formalité number. This is normal and accepted.

## Important Points to Watch Out For

| Area | Recommendation | Why |
| **Address** | Double-check carefully | Errors delay everything |
| **APE Code** | Use 85.59B for independent teaching | Most suitable code |
| **Versement Libératoire** | Start with "No" | More flexibility when income is low/zero |
| **Employees** | Select "No" if working alone | Keeps things simple |
| **ACRE** | Request it (Yes) | Reduces social contributions in year 1 |

## Next Steps (After Receiving Your SIRET)

- Create your account on [autoentrepreneur.urssaf.fr](https://autoentrepreneur.urssaf.fr)
- Declare your new activity to **France Travail**
- Start issuing proper invoices with your SIRET
- Declare your turnover every month (even if €0)

---

You are not alone. These procedures are very manageable when broken down into clear steps.

If this guide helped you, feel free to share it. I will continue documenting the full journey (receiving the SIRET, setting up URSSAF, first invoices, etc.).

**Next article**: I received my SIRET – What to do in the first 48 hours?

Take care,
**Sourov**"""


def set_slug(post_id: int, slug: str) -> dict:
    """Update post slug via deploy.php runner."""
    runner_php = f"""<?php
header('Content-Type: application/json');
if (($_GET['key'] ?? '') !== '0767044896thevenet_') {{ http_response_code(403); exit('{{"error":"forbidden"}}'); }}
require_once('/home/u839078121/domains/sourovdeb.com/public_html/wp-load.php');
$post_id = {post_id};
$slug = '{slug}';
$result = wp_update_post(['ID' => $post_id, 'post_name' => $slug], true);
$out = ['post_id' => $post_id, 'slug' => $slug, 'success' => !is_wp_error($result)];
if (is_wp_error($result)) $out['error'] = $result->get_error_message();
$out['self_deleted'] = @unlink(__FILE__);
echo json_encode($out);
"""
    import base64
    encoded = base64.b64encode(runner_php.encode()).decode()
    slug_name = f"slug-fix-{post_id}.php"
    deploy_data = urllib.parse.urlencode({
        "action": "upload",
        "path": slug_name,
        "encoded": "true",
        "content": encoded,
    }).encode()
    req = urllib.request.Request(
        f"https://www.sourovdeb.com/deploy.php?key={API_KEY}",
        data=deploy_data,
        method="POST",
        headers={"Content-Type": "application/x-www-form-urlencoded"},
    )
    with urllib.request.urlopen(req, timeout=60) as resp:
        upload_result = json.loads(resp.read().decode())
    with urllib.request.urlopen(
        f"https://www.sourovdeb.com/{slug_name}?key={API_KEY}", timeout=60
    ) as resp:
        return json.loads(resp.read().decode())


import urllib.parse

POSTS = [
    {
        "title": "Comment créer sa micro-entreprise de prof d'anglais indépendant en France (guide étape par étape 2026)",
        "content_md": FR_MD,
        "status": "publish",
        "category": "Ressources",
        "tags": "micro-entreprise, création entreprise, prof indépendant, enseignant indépendant, La Réunion, 85.59B, ACRE, Guichet Unique, formalité création entreprise, cours particuliers anglais",
        "meta_description": "Guide complet, rassurant et très détaillé pour créer votre micro-entreprise en tant que professeur ou formateur indépendant d'anglais en France. Toutes les étapes expliquées simplement avec les pièges à éviter.",
        "seo_title": "Comment créer sa micro-entreprise de prof d'anglais indépendant en France (guide étape par étape 2026)",
        "slug": "comment-creer-micro-entreprise-prof-anglais-etape-par-etape",
    },
    {
        "title": "How to Create Your Micro-Entreprise as an Independent English Teacher in France (Step-by-Step Guide 2026)",
        "content_md": EN_MD,
        "status": "publish",
        "category": "Resources",
        "tags": "micro-entreprise, create micro business France, independent teacher, English tutor France, La Réunion, 85.59B, ACRE, Guichet Unique INPI, self-employed teacher",
        "meta_description": "A clear, reassuring, and detailed step-by-step guide to creating your micro-entreprise as an independent English teacher or language trainer in France. Everything explained simply, with common pitfalls to avoid and what happens after submission.",
        "seo_title": "How to Create Your Micro-Entreprise as an Independent English Teacher in France (Step-by-Step Guide 2026)",
        "slug": "how-to-create-micro-entreprise-english-teacher-france-step-by-step-2026",
    },
]


def main():
    results = []
    for post in POSTS:
        payload = {
            "title": post["title"],
            "content": md_to_html(post["content_md"]),
            "status": post["status"],
            "categories": [55],
            "tags": post["tags"],
            "meta_description": post["meta_description"],
            "seo_title": post["seo_title"],
        }
        print(f"Publishing: {post['title'][:60]}...")
        try:
            result = publish_post(payload)
            print(json.dumps(result, indent=2, ensure_ascii=False))
            if result.get("success") and result.get("post_id"):
                slug_result = set_slug(result["post_id"], post["slug"])
                print(f"Slug update: {json.dumps(slug_result)}")
                result["slug_set"] = slug_result
            results.append(result)
        except urllib.error.HTTPError as e:
            body = e.read().decode()
            print(f"HTTP {e.code}: {body}")
            results.append({"error": body, "code": e.code})
    print("\n=== SUMMARY ===")
    print(json.dumps(results, indent=2, ensure_ascii=False))


if __name__ == "__main__":
    main()
