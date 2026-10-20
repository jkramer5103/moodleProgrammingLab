"""Browser test of the task authoring assistant (uses the real AI – costs a few cents)."""
import re
from playwright.sync_api import sync_playwright

BASE = "http://192.168.2.223:8080"
SHOTS = "/work/shots/"
errors = []


def login(page, user):
    for _ in range(3):
        page.goto(BASE + "/login/index.php")
        if "/login/" not in page.url:
            return
        page.fill("#username", user)
        page.fill("#password", "Test1234!")
        page.click("#loginbtn")
        page.wait_for_load_state("networkidle")
        if "/login/" not in page.url:
            return
    raise RuntimeError("login failed")


def ask(page, text):
    page.fill(".mpl-au-input", text)
    page.click(".mpl-au-actions .btn-primary")
    page.wait_for_function("document.querySelector('.mpl-au-progress') && "
                           "!document.querySelector('.mpl-au-progress .spinner-border') && "
                           "!document.querySelector('.mpl-au-input').disabled", timeout=600000)
    msgs = page.locator(".mpl-au-assistant")
    print("AI:", msgs.last.inner_text()[:1500] if msgs.count() else page.inner_text(".mpl-au-progress"))


with sync_playwright() as p:
    b = p.chromium.launch()
    page = b.new_context(viewport={"width": 1440, "height": 950}).new_page()
    page.on("pageerror", lambda e: errors.append(str(e)))
    login(page, "lehrer")
    page.goto(BASE + "/course/modedit.php?add=mpl&type=&course=2&section=1&return=0&sr=0")
    page.wait_for_selector(".mpl-au-input")
    page.screenshot(path=SHOTS + "20_author_empty.png")
    ask(page, "Klasse 9, kennen if und Schleifen, noch keine Listen-Methoden. Programm: Man gibt nacheinander "
              "Einkaufspreise ein, 0 beendet die Eingabe. Danach Summe und teuerster Artikel ausgeben.")
    print("name:", page.input_value("#id_name"), "| tests:", page.locator(".mpl-tb-card").count())
    page.screenshot(path=SHOTS + "21_author_created.png", full_page=True)
    ask(page, "Bitte zusätzlich den Durchschnittspreis ausgeben (2 Nachkommastellen) und einen versteckten Test mit "
              "nur einem Artikel ergänzen.")
    print("name:", page.input_value("#id_name"), "| tests:", page.locator(".mpl-tb-card").count())
    page.screenshot(path=SHOTS + "22_author_changed.png", full_page=True)
    page.locator("#id_submitbutton").click()
    page.wait_for_load_state("networkidle")
    print("after save:", page.url)
    m = re.search(r"id=(\d+)", page.url)
    cmid = m.group(1) if m else None
    # reopen: chat history is kept
    if cmid:
        page.goto(BASE + "/course/modedit.php?update=" + cmid)
        page.wait_for_selector(".mpl-au")
        print("chat messages after reopening:", page.locator(".mpl-au-msg").count())
    b.close()
print("JS errors:", errors)
