"""Showcase screenshots (student + teacher), uses the real AI (a fraction of a cent).
   BASE=https://… python showcase.py   – screenshots in shots/showcase_*.png"""
import os
from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://192.168.2.223:8080")
SHOTS = "/work/shots/showcase_"
errors = []

COUNTING = '''def ausgeglichen(text):
    return (text.count("(") == text.count(")")
            and text.count("[") == text.count("]")
            and text.count("{") == text.count("}"))


def fehlerstelle(text):
    if ausgeglichen(text):
        return -1
    return 0
'''


def login(page, user):
    page.context.clear_cookies()
    for _ in range(3):
        page.goto(BASE + "/login/index.php")
        page.fill("#username", user)
        page.fill("#password", "Test1234!")
        page.click("#loginbtn")
        page.wait_for_load_state("networkidle")
        if "/login/" not in page.url:
            return
    raise RuntimeError("login failed")


def set_code(page, code):
    page.click(".mpl-editor .cm-content")
    page.keyboard.press("Control+a")
    page.keyboard.press("Delete")
    page.keyboard.insert_text(code)


def student(page):
    # Student: course overview, failed check with AI hint, console.
    login(page, "schueler2")
    page.goto(BASE + "/course/view.php?id=2")
    page.screenshot(path=SHOTS + "1_course.png")
    page.goto(BASE + "/mod/mpl/view.php?id=8")
    page.wait_for_selector(".mpl-editor .cm-content")
    set_code(page, COUNTING)
    page.screenshot(path=SHOTS + "2_task.png")
    page.click(".mpl-toolbar .btn-primary")
    page.wait_for_selector(".mpl-hint-btn", timeout=90000)
    page.screenshot(path=SHOTS + "3_check.png", full_page=True)
    page.locator(".mpl-hint-btn").first.click()
    page.wait_for_selector(".mpl-hint-card", timeout=90000)
    page.fill(".mpl-hint-followup input", "Kannst du mir einfach den Code schreiben?")
    page.press(".mpl-hint-followup input", "Enter")
    page.wait_for_selector(".mpl-hint-a", timeout=90000)
    page.locator(".mpl-hint-card").first.scroll_into_view_if_needed()
    page.screenshot(path=SHOTS + "4_hint.png", full_page=True)



with sync_playwright() as p:
    b = p.chromium.launch()
    page = b.new_context(viewport={"width": 1440, "height": 900}, device_scale_factor=1).new_page()
    page.on("pageerror", lambda e: errors.append(str(e)))

    if os.environ.get("PART", "all") in ("all", "student"):
        student(page)

    # Teacher: authoring assistant, submissions.
    login(page, "lehrer")
    page.goto(BASE + "/course/modedit.php?add=mpl&type=&course=2&section=1&return=0&sr=0")
    page.wait_for_selector(".mpl-au-input")
    page.fill(".mpl-au-input", "Klasse 8, kennen Schleifen und Listen. Schüler sollen eine Funktion schreiben, die in einer "
                               "Liste von Temperaturen die längste Serie von Tagen über 25 Grad findet.")
    page.click(".mpl-au-actions .btn-primary")
    page.wait_for_selector(".mpl-au-progress", timeout=20000)
    page.screenshot(path=SHOTS + "6_author_working.png")
    page.wait_for_function("!document.querySelector('.mpl-au-progress .spinner-border') && "
                           "!document.querySelector('.mpl-au-input').disabled", timeout=600000)
    page.locator(".mpl-au").screenshot(path=SHOTS + "7_author_panel.png")
    page.screenshot(path=SHOTS + "8_author_form.png", full_page=True)
    # Not saved: leave the form.
    page.goto(BASE + "/mod/mpl/view.php?id=8")
    page.wait_for_selector(".mpl-tab")
    page.locator(".mpl-tab", has_text="Abgaben").first.click()
    page.wait_for_timeout(1500)
    page.screenshot(path=SHOTS + "9_submissions.png", full_page=True)
    b.close()
print("JS errors:", errors)
