"""Browser test of data files, turtle, exam mode, feedback, individual dates and regrading.

    dev/e2e/run.sh features_e2e.py
"""
import re
import sys

from playwright.sync_api import sync_playwright

BASE = "http://localhost:8080"
SHOTS = "/work/shots/"
errors = []
failed = []


def check(cond, label):
    print(("ok   " if cond else "FAIL ") + label)
    if not cond:
        failed.append(label)


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
    raise RuntimeError("login failed for " + user)


def cmid(page, name):
    page.goto(BASE + "/course/view.php?id=2")
    href = page.locator("a:has-text('%s')" % name).first.get_attribute("href")
    return re.search(r"id=(\d+)", href).group(1)


def set_code(page, code):
    page.click(".mpl-editor .cm-content")
    page.keyboard.press("Control+a")
    page.keyboard.press("Delete")
    page.evaluate("(code) => document.querySelector('.mpl-editor').mplEditor.setValue(code)", code)


with sync_playwright() as p:
    b = p.chromium.launch()
    ctx = b.new_context(viewport={"width": 1440, "height": 950}, permissions=["clipboard-read", "clipboard-write"])
    page = ctx.new_page()
    page.on("console", lambda m: m.type == "error" and errors.append(m.text))
    page.on("pageerror", lambda e: errors.append(str(e)))
    page.on("dialog", lambda d: d.accept())

    login(page, "e2etest")
    ids = {n: cmid(page, n) for n in ["Wetterdaten", "Turtle: Treppe", "Klassenarbeit"]}
    print(ids)

    # ---- Data files: student sees the file tab, console reads the file, check works -------------
    page.goto(BASE + "/mod/mpl/view.php?id=" + ids["Wetterdaten"])
    page.wait_for_selector(".mpl-editor .cm-content")
    page.click(".mpl-tab:has-text('Dateien')")
    page.wait_for_selector(".mpl-files .mpl-file")
    check(page.locator(".mpl-files pre").first.inner_text().startswith("datum;temperatur;regen"), "file preview shown")
    check(page.locator(".mpl-files a[href*='pluginfile.php']").count() == 1, "download link")
    page.screenshot(path=SHOTS + "f1_files_tab.png")
    ref = page.evaluate("1")  # noqa
    set_code(page, 'summe = 0\nn = 0\nregen = 0\nwith open("wetter.csv", encoding="utf-8") as f:\n    next(f)\n'
                   '    for z in f:\n        d, t, r = z.strip().split(";")\n        summe += float(t)\n        n += 1\n'
                   '        if float(r) > 0:\n            regen += 1\nprint("Durchschnitt:", round(summe / n, 1))\n'
                   'print("Regentage:", regen)\n')
    page.click(".mpl-toolbar .btn-success")
    page.wait_for_selector(".mpl-console-output:has-text('Regentage: 3')", timeout=20000)
    check(True, "console run reads wetter.csv")
    page.click(".mpl-toolbar .btn-primary")
    page.wait_for_selector(".mpl-result-score", timeout=60000)
    score = page.inner_text(".mpl-result-score")
    check("7 / 7" in score.replace(",", "."), "check with file tests: " + score)
    page.screenshot(path=SHOTS + "f2_files_check.png", full_page=True)

    # ---- Turtle -----------------------------------------------------------------------------------
    page.goto(BASE + "/mod/mpl/view.php?id=" + ids["Turtle: Treppe"])
    page.wait_for_selector(".mpl-editor .cm-content")
    set_code(page, 'import turtle\n\nstufen = int(input("Wie viele Stufen? "))\nfor i in range(stufen):\n'
                   '    turtle.left(90)\n    turtle.forward(20)\n    turtle.right(90)\n    turtle.forward(20)\n\nturtle.done()\n')
    page.click(".mpl-toolbar .btn-success")
    page.wait_for_selector(".mpl-console-output:has-text('Stufen?')", timeout=20000)
    page.fill(".mpl-console-input", "5")
    page.press(".mpl-console-input", "Enter")
    page.wait_for_selector(".mpl-console-output img.mpl-console-plot", timeout=20000)
    check(True, "turtle drawing shown in the console")
    page.screenshot(path=SHOTS + "f3_turtle_console.png")
    page.click(".mpl-toolbar .btn-primary")
    page.wait_for_selector(".mpl-result-score", timeout=60000)
    score = page.inner_text(".mpl-result-score")
    check("10 / 10" in score, "turtle check full points: " + score)
    # Wrong: staircase goes down.
    set_code(page, 'import turtle\nstufen = int(input())\nfor i in range(stufen):\n    turtle.right(90)\n'
                   '    turtle.forward(20)\n    turtle.left(90)\n    turtle.forward(20)\n')
    page.click(".mpl-toolbar .btn-primary")
    page.wait_for_function("document.querySelector('.mpl-result-score') && "
                           "!document.querySelector('.mpl-result-score').innerText.includes('10 / 10')", timeout=60000)
    page.locator(".mpl-test").first.scroll_into_view_if_needed()
    check(page.locator(".mpl-plot-compare img").count() >= 2, "student and expected drawing shown")
    page.screenshot(path=SHOTS + "f4_turtle_wrong.png", full_page=True)

    # ---- Exam mode: notice, paste guard, window changes ---------------------------------------------
    page.goto(BASE + "/mod/mpl/view.php?id=" + ids["Klassenarbeit"])
    page.wait_for_selector(".mpl-editor .cm-content")
    check(page.locator(".mpl-exam-notice").count() == 1, "exam notice for students")
    check(page.locator(".mpl-tab:has-text('KI')").count() == 0, "no AI tab in exam mode")
    page.click(".mpl-editor .cm-content")
    page.evaluate("""() => {
        const dt = new DataTransfer();
        dt.setData('text/plain', 'def maximum(zahlen):\\n    return max(zahlen)\\n');
        document.querySelector('.mpl-editor .cm-content').dispatchEvent(new ClipboardEvent('paste', {clipboardData: dt,
            bubbles: true, cancelable: true}));
    }""")
    page.wait_for_selector(".mpl-exam-msg:not([hidden])", timeout=5000)
    code = page.evaluate("document.querySelector('.mpl-editor').mplEditor.getValue()")
    check("return max" not in code, "paste from outside blocked")
    page.screenshot(path=SHOTS + "f5_exam_paste.png")
    # Copy & paste inside the editor is allowed.
    page.evaluate("""() => {
        const view = document.querySelector('.mpl-editor').mplEditor.view;
        view.dispatch({selection: {anchor: 0, head: 3}});
        const dt = new DataTransfer();
        view.contentDOM.dispatchEvent(new ClipboardEvent('copy', {clipboardData: dt, bubbles: true, cancelable: true}));
        view.dispatch({selection: {anchor: view.state.doc.length}});
        const dt2 = new DataTransfer();
        dt2.setData('text/plain', 'def');
        view.contentDOM.dispatchEvent(new ClipboardEvent('paste', {clipboardData: dt2, bubbles: true, cancelable: true}));
    }""")
    code = page.evaluate("document.querySelector('.mpl-editor').mplEditor.getValue()")
    check(code.rstrip().endswith("def"), "copy & paste inside the editor allowed")
    # Leave the window (other tab) for 2 seconds.
    other = ctx.new_page()
    other.goto(BASE + "/login/index.php")
    page.evaluate("""() => {
        Object.defineProperty(document, 'hidden', {configurable: true, get: () => true});
        document.dispatchEvent(new Event('visibilitychange'));
    }""")
    page.wait_for_timeout(2200)
    page.evaluate("""() => {
        Object.defineProperty(document, 'hidden', {configurable: true, get: () => false});
        document.dispatchEvent(new Event('visibilitychange'));
    }""")
    other.close()
    page.wait_for_selector(".mpl-exam-msg:has-text('verlassen')", timeout=5000)
    check(True, "student sees that leaving was logged")
    set_code(page, "def maximum(zahlen):\n    g = zahlen[0]\n    for z in zahlen:\n        if z > g:\n            g = z\n"
                   "    return g\n")
    page.click(".mpl-toolbar .btn-warning")
    page.click(".modal-footer .btn-primary")
    page.wait_for_selector(".mpl-result-score", timeout=60000)
    check("10 / 10" in page.inner_text(".mpl-result-score"), "exam submission graded")

    # ---- Teacher: exam monitor, exam log, feedback ---------------------------------------------------
    login(page, "lehrer")
    page.goto(BASE + "/mod/mpl/view.php?id=" + ids["Klassenarbeit"])
    page.wait_for_selector(".mpl-exam-teacher")
    page.screenshot(path=SHOTS + "f6_exam_teacher.png")
    page.goto(BASE + "/mod/mpl/submissions.php?id=" + ids["Klassenarbeit"])
    row = page.locator("tr:has-text('E2E Testkonto')")
    text = row.inner_text()
    check("1×" in text, "exam monitor shows the window change: " + text.replace("\n", " | ")[:200])
    page.screenshot(path=SHOTS + "f7_exam_monitor.png", full_page=True)
    row.locator("a:has-text('Ansehen')").click()
    page.wait_for_selector(".mpl-editor .cm-gutters")
    page.locator(".cm-lineNumbers .cm-gutterElement:has-text('4')").first.click()
    page.fill(".mpl-fb-line textarea", "Gut: Hier vergleichst du jede Zahl mit dem bisher größten Wert.")
    page.click(".mpl-fb-line .btn-primary")
    page.wait_for_selector(".mpl-fb-line .mpl-fb-text")
    page.click(".mpl-feedback-box .btn-outline-primary")
    page.fill(".mpl-feedback-box textarea", "Sehr ordentlich gelöst!")
    page.click(".mpl-feedback-box .btn-primary")
    page.wait_for_selector(".mpl-feedback-box .mpl-fb-text")
    check(page.locator(".mpl-fb-text").count() == 2, "teacher wrote a line and a general comment")
    page.screenshot(path=SHOTS + "f8_feedback_teacher.png", full_page=True)

    # ---- Teacher: individual dates -------------------------------------------------------------------
    page.goto(BASE + "/mod/mpl/overrides.php?id=" + ids["Klassenarbeit"] + "&action=edit&target=user")
    page.wait_for_selector("#id_userid", state="attached")
    page.evaluate("""() => {
        const sel = document.querySelector('#id_userid');
        const opt = [...sel.options].find((o) => o.text.includes('E2E'));
        sel.value = opt.value;
        sel.dispatchEvent(new Event('change', {bubbles: true}));
    }""")
    page.select_option("#id_maxattempts", "5")
    page.click("#id_submitbutton")
    page.wait_for_selector("table.generaltable")
    check("E2E Testkonto" in page.inner_text("table.generaltable"), "override saved")
    page.screenshot(path=SHOTS + "f9_overrides.png", full_page=True)

    # ---- Teacher: regrade after a test change --------------------------------------------------------
    page.goto(BASE + "/mod/mpl/regrade.php?id=" + ids["Klassenarbeit"])
    page.click("button:has-text('Probelauf')")
    page.wait_for_selector(".alert-success, .alert-warning", timeout=120000)
    check("nichts gespeichert" in page.inner_text("body"), "regrade dry run")
    page.screenshot(path=SHOTS + "f10_regrade.png", full_page=True)

    # ---- Teacher: settings form with data files and turtle test --------------------------------------
    page.goto(BASE + "/course/modedit.php?update=" + ids["Wetterdaten"])
    page.wait_for_selector(".mpl-datafiles .mpl-file")
    check("wetter.csv" in page.inner_text(".mpl-datafiles"), "form lists the data file")
    page.set_input_files(".mpl-datafiles input[type=file]", files=[{"name": "extra.txt", "mimeType": "text/plain",
                                                                  "buffer": b"hallo\nwelt\n"}])
    page.wait_for_selector(".mpl-datafiles .mpl-file:has-text('extra.txt')", timeout=15000)
    check(True, "upload in the form")
    page.locator(".mpl-datafiles .mpl-file:has-text('extra.txt') .btn-outline-danger").click()
    page.wait_for_function("!document.querySelector('.mpl-datafiles').innerText.includes('extra.txt')", timeout=15000)
    check(True, "delete in the form")
    page.locator(".mpl-datafiles").scroll_into_view_if_needed()
    page.screenshot(path=SHOTS + "f11_form_files.png")
    page.click("button:has-text('Musterlösung testen')")
    page.wait_for_selector(".mpl-tb-validate .mpl-result-score", timeout=60000)
    check("10 / 10" in page.inner_text(".mpl-tb-validate .mpl-result-score"), "validate in the form uses the files")
    page.goto(BASE + "/course/modedit.php?update=" + ids["Turtle: Treppe"])
    page.wait_for_selector(".mpl-tb-card")
    page.locator(".mpl-tb-card").first.locator(".mpl-tb-head .btn-link").first.click()
    page.wait_for_selector(".mpl-tb-criteria")
    page.locator(".mpl-tb-card").first.scroll_into_view_if_needed()
    page.screenshot(path=SHOTS + "f12_form_turtle.png")
    page.locator("#id_examheader a.fheader, #id_examheader .ftoggler a").first.click()
    page.check("#id_exammode")
    page.wait_for_selector("#id_sebmode", state="visible")
    page.select_option("#id_sebmode", "1")
    page.locator("#id_sebmode").scroll_into_view_if_needed()
    page.screenshot(path=SHOTS + "f13_form_exam.png")
    page.click("#id_submitbutton2")
    page.wait_for_load_state("networkidle")

    # ---- SEB gate for students -------------------------------------------------------------------------
    login(page, "e2etest")
    page.goto(BASE + "/mod/mpl/view.php?id=" + ids["Turtle: Treppe"])
    page.wait_for_selector(".mpl-seb-gate")
    check(page.locator(".mpl-seb-gate a[href^='seb://']").count() == 1, "SEB gate with start link")
    page.screenshot(path=SHOTS + "f14_seb_gate.png")
    resp = page.request.get(BASE + "/mod/mpl/seb.php?id=" + ids["Turtle: Treppe"])
    check(resp.status == 200 and b"<key>startURL</key>" in resp.body(), "SEB config download")

    # Student sees the feedback.
    page.goto(BASE + "/mod/mpl/view.php?id=" + ids["Klassenarbeit"])
    page.wait_for_selector(".mpl-editor .cm-content")
    check(page.locator(".alert:has-text('Feedback')").count() >= 1, "student is told about the feedback")
    page.click(".mpl-tab:has-text('Meine Abgaben')")
    page.click(".mpl-feedback-badge")
    page.wait_for_selector(".mpl-fb-line .mpl-fb-text")
    check("größten" in page.inner_text(".mpl-fb-line"), "student sees the line comment")
    check(page.locator(".mpl-fb-line button").count() == 0, "student cannot edit comments")
    page.screenshot(path=SHOTS + "f15_feedback_student.png", full_page=True)

    # Clean up: exam mode for the turtle task off again.
    login(page, "lehrer")
    page.goto(BASE + "/course/modedit.php?update=" + ids["Turtle: Treppe"])
    page.locator("#id_examheader a.fheader, #id_examheader .ftoggler a").first.click()
    page.uncheck("#id_exammode")
    page.click("#id_submitbutton2")
    page.wait_for_load_state("networkidle")
    b.close()

relevant = [e for e in errors if "favicon" not in e]
print("JS errors:", relevant)
if failed or relevant:
    print("FAILED:", failed)
    sys.exit(1)
print("ALL OK")
