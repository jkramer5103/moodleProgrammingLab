"""Screenshots of all places with icons (student + teacher). BASE=… python icons_shots.py"""
import os
from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://192.168.2.223:8080")
OUT = "/work/shots/icons_"
errors = []


def login(page, user):
    page.context.clear_cookies()
    for _ in range(4):
        page.goto(BASE + "/login/index.php")
        page.fill("#username", user)
        page.fill("#password", "Test1234!")
        page.click("#loginbtn")
        page.wait_for_load_state("networkidle")
        if "/login/" not in page.url:
            return
    raise RuntimeError("login failed: " + user)


with sync_playwright() as p:
    b = p.chromium.launch()
    page = b.new_context(viewport={"width": 1440, "height": 1000}).new_page()
    page.on("pageerror", lambda e: errors.append(str(e)))
    login(page, "schueler2")
    page.goto(BASE + "/mod/mpl/view.php?id=8")
    page.wait_for_selector(".mpl-editor .cm-content")
    page.click(".mpl-toolbar .btn-primary")
    page.wait_for_selector(".mpl-hint-btn", timeout=90000)
    page.evaluate("document.querySelector('.mpl-toolbar').scrollIntoView({block:'start'}); window.scrollBy(0,-80)")
    page.screenshot(path=OUT + "1_check.png")
    page.evaluate("document.querySelector('.mpl-hidden-summary') && document.querySelector('.mpl-hidden-summary').scrollIntoView({block:'center'})")
    page.screenshot(path=OUT + "2_check_bottom.png")
    page.locator(".mpl-tab", has_text="Hinweise").first.click()
    page.evaluate("document.querySelector('.mpl-toolbar').scrollIntoView({block:'start'}); window.scrollBy(0,-80)")
    page.screenshot(path=OUT + "3_hints.png")
    page.goto(BASE + "/mod/mpl/view.php?id=6")
    page.wait_for_selector(".mpl-editor .cm-content")
    page.click(".mpl-editor .cm-content")
    page.keyboard.press("Control+a")
    page.keyboard.insert_text("import time\nprint('läuft')\ntime.sleep(20)\n")
    page.click(".mpl-toolbar .btn-success")
    page.wait_for_selector(".mpl-toolbar .btn-danger", timeout=20000)
    page.evaluate("document.querySelector('.mpl-toolbar').scrollIntoView({block:'start'}); window.scrollBy(0,-80)")
    page.screenshot(path=OUT + "4_running.png")
    page.click(".mpl-toolbar .btn-danger")
    page.wait_for_timeout(2000)

    login(page, "lehrer")
    page.goto(BASE + "/course/modedit.php?update=8")
    page.wait_for_selector(".mpl-tb-card")
    page.locator(".mpl-tb-card").first.scroll_into_view_if_needed()
    page.locator(".mpl-tb-card .mpl-tb-head button").first.click()
    page.wait_for_timeout(500)
    page.locator(".mpl-tb-card").first.scroll_into_view_if_needed()
    page.screenshot(path=OUT + "5_builder.png")
    page.locator(".mpl-au").scroll_into_view_if_needed()
    page.locator(".mpl-au").screenshot(path=OUT + "6_author_empty.png")
    page.goto(BASE + "/course/modedit.php?add=mpl&type=&course=2&section=1&return=0&sr=0")
    page.wait_for_selector(".mpl-au-input")
    page.fill(".mpl-au-input", "Klasse 9: Funktion ist_schaltjahr(jahr) mit den üblichen Regeln.")
    page.click(".mpl-au-actions .btn-primary")
    page.wait_for_function("document.querySelector('.mpl-au-validation')", timeout=600000)
    page.locator(".mpl-au").screenshot(path=OUT + "7_author.png")
    b.close()
print("JS errors:", errors)
