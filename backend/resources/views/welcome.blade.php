<!DOCTYPE html>
{{--
    Shown only when the built web application is missing from public/.

    This replaced Laravel's stock welcome page deliberately. On a client PC the
    stock page was actively misleading: it appeared after a partially completed
    installation and read as an advert for a framework the pharmacy has never
    heard of, giving no hint that anything was wrong or what to do. This page
    states the actual situation and who should do what.

    The data-pv-placeholder attribute is load-bearing: the installer's health
    check tells this page apart from the real application by it (and by the
    absence of the SPA's id="root" mount point). Do not remove it.
--}}
<html lang="en" data-pv-placeholder="true">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PharmaVerify — setup incomplete</title>
    <style>
        body { margin: 0; font-family: -apple-system, "Segoe UI", system-ui, sans-serif;
               background: #FBFCFB; color: #12211A; display: grid; min-height: 100vh; place-items: center; }
        main { max-width: 34rem; padding: 2rem; }
        h1 { font-size: 1.4rem; margin: 0 0 .75rem; }
        h1 span { color: #1E7A4A; }
        p { line-height: 1.6; margin: 0 0 1rem; color: #4A5C52; }
        code { background: #F2F6F3; border: 1px solid #DCE5DF; border-radius: 4px; padding: .1rem .35rem; font-size: .85em; }
        .tag { display: inline-block; background: #FBF0DE; color: #B26A00; border-radius: 4px;
               padding: .15rem .5rem; font-size: .75rem; font-weight: 600; letter-spacing: .05em;
               text-transform: uppercase; margin-bottom: 1rem; }
    </style>
</head>
<body>
    <main>
        <span class="tag">Setup incomplete</span>
        <h1><span>PharmaVerify</span> is running, but the application screens are not installed</h1>
        <p>The server answered — so the name, the port and the web server are all
           working. What is missing is the built web application, which belongs in
           this installation's <code>public</code> folder and was not found there.</p>
        <p><strong>If this is a client PC:</strong> run the PharmaVerify installer
           again, or open <em>Start&nbsp;menu → PharmaVerify → PharmaVerify Health</em>
           for a report naming exactly what is missing.</p>
        <p><strong>If this is a development checkout:</strong> nothing is wrong —
           the front end is served separately during development, and this page
           simply means it has not been built into <code>backend/public</code>.</p>
    </main>
</body>
</html>
