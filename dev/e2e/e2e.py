import re, sys, json
from playwright.sync_api import sync_playwright, expect

BASE = "http://localhost:8080"
SHOTS = "/work/shots/"
errors = []

def login(page, user):
    for attempt in range(3):
        page.goto(BASE + "/login/index.php")
        if "/login/" not in page.url:
            return
        page.fill("#username", user)
        page.fill("#password", "Test1234!" if user != "admin" else "Admin1234!")
        page.click("#loginbtn")
        page.wait_for_load_state("networkidle")
        if "/login/" not in page.url:
            return
    raise RuntimeError("login failed for " + user)


def cmid(page, name):
    page.goto(BASE + "/course/view.php?id=2")
    href = page.locator("a:has-text('%s')" % name).first.get_attribute("href")
    return re.search(r"id=(\d+)", href).group(1)

with sync_playwright() as p:
    b = p.chromium.launch()
    ctx = b.new_context(viewport={"width": 1440, "height": 950})
    page = ctx.new_page()
    page.on("console", lambda m: m.type == "error" and errors.append(m.text))
    page.on("pageerror", lambda e: errors.append(str(e)))

    login(page, "e2etest")
    ids = {n: cmid(page, n) for n in ["Zahlen verdoppeln", "Primzahlen", "Caesar", "Bankkonto", "Temperaturverlauf"]}
    print(ids)

    # 1: console run with input
    page.goto(BASE + "/mod/mpl/view.php?id=" + ids["Zahlen verdoppeln"])
    page.wait_for_selector(".mpl-editor .cm-content")
    page.click(".mpl-editor .cm-content")
    page.keyboard.press("Control+a")
    page.keyboard.type("zahl = int(input(\"Zahl: \"))\nprint(\"Ergebnis:\", zahl * 2)\n")
    page.click(".mpl-toolbar .btn-success")
    page.wait_for_selector(".mpl-console-output:has-text('Zahl:')", timeout=20000)
    page.fill(".mpl-console-input", "21")
    page.press(".mpl-console-input", "Enter")
    page.wait_for_selector(".mpl-console-output:has-text('Ergebnis: 42')", timeout=20000)
    page.wait_for_selector(".mpl-out-sys", timeout=20000)
    page.screenshot(path=SHOTS + "1_console.png")
    print("console ok")

    # check (fails partially because of prompt? uses contains/lines)
    page.click(".mpl-toolbar .btn-primary")
    page.wait_for_selector(".mpl-result-score", timeout=60000)
    print("check:", page.inner_text(".mpl-result-score"))
    page.screenshot(path=SHOTS + "2_check.png", full_page=True)

    # 3: caesar wrong solution -> feedback with diff
    page.goto(BASE + "/mod/mpl/view.php?id=" + ids["Caesar"])
    page.wait_for_selector(".mpl-editor .cm-content")
    page.click(".mpl-editor .cm-content")
    page.keyboard.press("Control+a")
    page.keyboard.type("def encrypt(text, shift):\nreturn text[::-1]\n")
    page.click(".mpl-toolbar .btn-primary")
    page.wait_for_selector(".mpl-result-score", timeout=60000)
    print("caesar check:", page.inner_text(".mpl-result-score"))
    page.screenshot(path=SHOTS + "3_caesar_feedback.png", full_page=True)
    # submit
    page.click(".mpl-toolbar .btn-warning")
    page.locator(".modal button.btn-primary").click()
    page.wait_for_selector(".mpl-result-title:has-text('Versuch'), .mpl-result-title:has-text('Attempt')", timeout=60000)
    print("submit:", page.inner_text(".mpl-result-head"))
    page.click(".mpl-tab:nth-child(3)")
    page.screenshot(path=SHOTS + "4_attempts.png")

    # error traceback
    page.click(".mpl-editor .cm-content")
    page.keyboard.press("Control+a")
    page.keyboard.type("def encrypt(text, shift):\nreturn text + shift\n")
    page.click(".mpl-toolbar .btn-primary")
    page.wait_for_selector(".mpl-pre-error", timeout=60000)
    page.screenshot(path=SHOTS + "5_traceback.png", full_page=True)

    # AI hint at a failed test (needs aihints enabled; works with the mock AI too)
    page.goto(BASE + "/mod/mpl/view.php?id=" + ids["Caesar"])
    page.wait_for_selector(".mpl-editor .cm-content")
    page.click(".mpl-editor .cm-content")
    page.keyboard.press("Control+a")
    page.keyboard.type("def encrypt(text, shift):\nreturn text\n")
    page.click(".mpl-toolbar .btn-primary")
    page.wait_for_selector(".mpl-hint-btn", timeout=60000)
    print("hint button:", page.inner_text(".mpl-hint-btn >> nth=0"))
    page.locator(".mpl-hint-btn").first.click()
    page.wait_for_selector(".mpl-hint-card", timeout=90000)
    print("hint:", page.inner_text(".mpl-hint-card .mpl-hint-text")[:120].replace("\n", " "))
    page.fill(".mpl-hint-followup input", "Was heißt das genau?")
    page.press(".mpl-hint-followup input", "Enter")
    page.wait_for_selector(".mpl-hint-a", timeout=90000)
    page.screenshot(path=SHOTS + "13_hint.png", full_page=True)
    # second hint costs points -> confirm dialog
    page.wait_for_timeout(10500)
    page.click(".mpl-tab:nth-child(2)")
    page.locator(".mpl-hint-btn").first.click()
    page.wait_for_selector(".modal .modal-body", timeout=10000)
    print("confirm:", page.inner_text(".modal .modal-body")[:140])
    page.screenshot(path=SHOTS + "14_hint_confirm.png")
    page.locator(".modal button.btn-primary").click()
    page.wait_for_selector(".mpl-hint-card >> nth=1", timeout=90000)
    print("status:", page.inner_text(".mpl-hint-status"))

    # console error -> explain
    page.goto(BASE + "/mod/mpl/view.php?id=" + ids["Zahlen verdoppeln"])
    page.wait_for_selector(".mpl-editor .cm-content")
    page.click(".mpl-editor .cm-content")
    page.keyboard.press("Control+a")
    page.keyboard.type("zahl = input('Zahl: ')\nprint(zahl * 2 + 1)\n")
    page.click(".mpl-toolbar .btn-success")
    page.wait_for_selector(".mpl-console-output:has-text('Zahl:')", timeout=20000)
    page.fill(".mpl-console-input", "4")
    page.press(".mpl-console-input", "Enter")
    page.wait_for_selector(".mpl-explain-slot .mpl-hint-btn", timeout=30000)
    page.screenshot(path=SHOTS + "15_console_explain.png")
    page.click(".mpl-explain-slot .mpl-hint-btn")
    page.wait_for_selector(".mpl-hint-card", timeout=90000)
    print("console hint ok")

    # 5: plot
    page.goto(BASE + "/mod/mpl/view.php?id=" + ids["Temperaturverlauf"])
    page.wait_for_selector(".mpl-editor .cm-content")
    page.click(".mpl-editor .cm-content")
    page.keyboard.press("Control+a")
    page.keyboard.type("import matplotlib.pyplot as plt\ntage = [1, 2, 3, 4, 5, 6, 7]\ntemperatur = [18, 20, 21, 19, 17, 22, 24]\nplt.bar(tage, temperatur)\nplt.title('Woche')\nplt.show()\n")
    page.click(".mpl-toolbar .btn-success")
    page.wait_for_selector(".mpl-console-plot", timeout=30000)
    page.click(".mpl-toolbar .btn-primary")
    page.wait_for_selector(".mpl-plot-img", timeout=60000)
    print("plot check:", page.inner_text(".mpl-result-score"))
    page.screenshot(path=SHOTS + "6_plot.png", full_page=True)

    # hardcode_check: primes hard-coded -> 0 points, expected hidden
    page.goto(BASE + "/mod/mpl/view.php?id=" + ids["Primzahlen"])
    page.wait_for_selector(".mpl-editor .cm-content")
    page.click(".mpl-editor .cm-content")
    page.keyboard.press("Control+a")
    page.keyboard.type("print([2, 3, 5, 7, 11, 13, 17, 19, 23, 29, 31, 37, 41, 43, 47, 53, 59, 61, 67, 71, 73, 79, 83, 89, 97])")
    page.click(".mpl-toolbar .btn-primary")
    page.wait_for_selector(".mpl-results .alert-danger", timeout=60000)
    print("hardcoded primes:", page.inner_text(".mpl-result-score"), "|", page.inner_text(".mpl-results .alert-danger"))
    page.screenshot(path=SHOTS + "11_hardcoded.png", full_page=True)

    # teacher: form with test builder + validate
    ctx = b.new_context(viewport={"width": 1440, "height": 950})
    page = ctx.new_page()
    page.on("pageerror", lambda e: errors.append(str(e)))
    login(page, "lehrer")
    page.goto(BASE + "/course/modedit.php?update=" + ids["Caesar"])
    try:
        page.wait_for_selector(".mpl-tb-card", timeout=20000)
    except Exception:
        page.screenshot(path=SHOTS + "fail_form.png", full_page=True)
        print("URL:", page.url)
        raise
    page.locator(".mpl-tb-card .mpl-tb-head button.btn-link").nth(2).click()
    page.locator(".mpl-tb-card .mpl-tb-head button.btn-link").nth(3).click()
    page.wait_for_selector(".mpl-tb-body")
    page.locator("button:has-text('Musterlösung testen'), button:has-text('Test reference solution')").click()
    page.wait_for_selector(".mpl-tb-validate .mpl-result-score", timeout=60000)
    print("validate:", page.inner_text(".mpl-tb-validate .mpl-result-score"))
    page.locator("#id_testsheader").scroll_into_view_if_needed()
    page.screenshot(path=SHOTS + "7_builder.png", full_page=True)
    # save the form unchanged (round trip of the builder JSON)
    page.locator("#id_submitbutton2").click()
    page.wait_for_load_state("networkidle")
    print("saved form ->", page.url)

    # builder with a code check test
    page.goto(BASE + "/course/modedit.php?update=" + ids["Primzahlen"])
    page.wait_for_selector(".mpl-tb-card")
    page.locator(".mpl-tb-card .mpl-tb-head button.btn-link").nth(2).click()
    page.wait_for_selector(".mpl-tb-body")
    page.screenshot(path=SHOTS + "12_codecheck_builder.png", full_page=True)

    # new activity form
    page.goto(BASE + "/course/modedit.php?add=mpl&type=&course=2&section=1&return=0&sr=0")
    page.wait_for_selector(".mpl-tb")
    page.screenshot(path=SHOTS + "8_newform.png", full_page=True)

    page.goto(BASE + "/mod/mpl/submissions.php?id=" + ids["Caesar"])
    page.screenshot(path=SHOTS + "9_submissions.png", full_page=True)
    page.locator("a[href*='hints.php']").first.click()
    page.wait_for_selector(".mpl-hint-card")
    page.screenshot(path=SHOTS + "16_teacher_hints.png", full_page=True)
    print("teacher hints:", page.locator(".mpl-hint-card").count())
    page.goto(BASE + "/mod/mpl/submissions.php?id=" + ids["Caesar"])
    page.locator("a.btn.btn-sm").first.click()
    page.wait_for_selector(".mpl-test")
    page.screenshot(path=SHOTS + "10_submission.png", full_page=True)
    b.close()

print("JS errors:", json.dumps(errors, indent=1))
