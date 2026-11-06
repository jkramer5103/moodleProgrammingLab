"""Headless replacement for Python's ``turtle`` module.

The sandbox has no display (and no Tk), so the harness installs this module
as ``turtle``. It implements the commonly used turtle API, records everything
that is drawn (pen strokes, fills, dots, texts) and renders the drawing to a
PNG with matplotlib instead of animating it in a window.

The recording is what tests compare (student drawing vs. reference drawing);
the PNG is shown in the console and in the test results.
"""

import base64
import builtins
import io
import math

__all__ = []  # filled at the end ("from turtle import *")

MAX_ITEMS = 50000
MAX_TIMER_CALLS = 2000

SHAPES = {
    "classic": ((0, 0), (-5, -9), (0, -7), (5, -9)),
    "arrow": ((-10, 0), (10, 0), (0, 10)),
    "turtle": ((0, 16), (-2, 14), (-1, 10), (-4, 7), (-7, 9), (-9, 8), (-6, 5), (-7, 1), (-5, -3), (-8, -6),
               (-6, -8), (-4, -5), (0, -7), (4, -5), (6, -8), (8, -6), (5, -3), (7, 1), (6, 5), (9, 8), (7, 9),
               (4, 7), (1, 10), (2, 14)),
    "circle": tuple((10 * math.cos(math.radians(a)), 10 * math.sin(math.radians(a))) for a in range(0, 360, 18)),
    "square": ((10, -10), (10, 10), (-10, 10), (-10, -10)),
    "triangle": ((10, -5.77), (0, 11.55), (-10, -5.77)),
    "blank": (),
}


class TurtleGraphicsError(Exception):
    """Same name as in the standard library."""


class Terminator(Exception):
    """Raised by the standard library when the window was closed."""


class Vec2D(tuple):
    """2D vector (as in the standard library)."""

    def __new__(cls, x, y):
        return tuple.__new__(cls, (x, y))

    def __add__(self, other):
        return Vec2D(self[0] + other[0], self[1] + other[1])

    def __mul__(self, other):
        if isinstance(other, Vec2D):
            return self[0] * other[0] + self[1] * other[1]
        return Vec2D(self[0] * other, self[1] * other)

    def __rmul__(self, other):
        if isinstance(other, (int, float)):
            return Vec2D(self[0] * other, self[1] * other)
        return NotImplemented

    def __sub__(self, other):
        return Vec2D(self[0] - other[0], self[1] - other[1])

    def __neg__(self):
        return Vec2D(-self[0], -self[1])

    def __abs__(self):
        return math.hypot(*self)

    def rotate(self, angle):
        perp = Vec2D(-self[1], self[0])
        angle = math.radians(angle)
        c, s = math.cos(angle), math.sin(angle)
        return Vec2D(self[0] * c + perp[0] * s, self[1] * c + perp[1] * s)

    def __getnewargs__(self):
        return (self[0], self[1])

    def __repr__(self):
        return "(%.2f,%.2f)" % self


# --------------------------------------------------------------------------
# Colours
# --------------------------------------------------------------------------

def _hex(rgb):
    return "#%02x%02x%02x" % tuple(max(0, min(255, int(round(v * 255)))) for v in rgb)


def normalise_color(value, colormode=1.0):
    """Colour in any turtle notation -> "#rrggbb" (or the cleaned name if unknown)."""
    if isinstance(value, (tuple, list)):
        if len(value) != 3:
            raise TurtleGraphicsError("bad color sequence: %s" % (value,))
        r, g, b = value
        if colormode == 255:
            if not all(0 <= v <= 255 for v in (r, g, b)):
                raise TurtleGraphicsError("bad color sequence: %s" % (value,))
            return _hex((r / 255.0, g / 255.0, b / 255.0))
        if not all(0 <= v <= 1.0 for v in (r, g, b)):
            raise TurtleGraphicsError("bad color sequence: %s" % (value,))
        return _hex((r, g, b))
    text = str(value).strip()
    if text == "":
        return ""
    try:
        from matplotlib import colors
        name = text.lower()
        for candidate in (name, name.replace(" ", ""), name.rstrip("0123456789").replace(" ", "")):
            if colors.is_color_like(candidate):
                return colors.to_hex(candidate)
    except Exception:  # pragma: no cover - matplotlib is always there
        pass
    if text.startswith("#") and len(text) in (4, 7):
        return text.lower()
    raise TurtleGraphicsError("bad color string: %s" % text)


# --------------------------------------------------------------------------
# Screen
# --------------------------------------------------------------------------

class _Screen:
    """The (single) drawing surface."""

    def __init__(self):
        self._reset_state()

    def _reset_state(self):
        self.items = []          # drawing items in z-order
        self.turtles_list = []
        self.bg = "#ffffff"
        self.colormode_value = 1.0
        self.mode_value = "standard"
        self.world = None        # (llx, lly, urx, ury) from setworldcoordinates
        self.size = None         # (width, height) from setup()
        self.title_text = ""
        self.timers = []
        self.shapes = dict(SHAPES)
        self.truncated = False
        self.shown_items = -1

    # -- recording ------------------------------------------------------

    def add(self, item):
        if len(self.items) >= MAX_ITEMS:
            self.truncated = True
            return None
        self.items.append(item)
        return item

    # -- public API -----------------------------------------------------

    def bgcolor(self, *args):
        if not args:
            return self.bg
        self.bg = normalise_color(args[0] if len(args) == 1 else args, self.colormode_value)

    def bgpic(self, picname=None):
        return "nopic"

    def colormode(self, cmode=None):
        if cmode is None:
            return self.colormode_value
        if cmode not in (1.0, 1, 255):
            raise TurtleGraphicsError("colormode must be 1.0 or 255")
        self.colormode_value = 255 if cmode == 255 else 1.0

    def mode(self, mode=None):
        if mode is None:
            return self.mode_value
        mode = mode.lower()
        if mode not in ("standard", "logo", "world"):
            raise TurtleGraphicsError("No turtle-graphics-mode %s" % mode)
        self.mode_value = mode
        for t in self.turtles_list:
            t.reset()

    def setworldcoordinates(self, llx, lly, urx, ury):
        self.mode_value = "world"
        self.world = (float(llx), float(lly), float(urx), float(ury))

    def setup(self, width=None, height=None, startx=None, starty=None):
        def dim(v, full):
            if v is None:
                return None
            if isinstance(v, float) and 0 < v <= 1:
                return v * full
            return float(v)
        w, h = dim(width, 1600), dim(height, 1000)
        if w and h:
            self.size = (w, h)

    def screensize(self, canvwidth=None, canvheight=None, bg=None):
        if canvwidth is None and canvheight is None and bg is None:
            return (400, 300)
        if bg is not None:
            self.bgcolor(bg)

    def window_width(self):
        return int(self.size[0]) if self.size else 640

    def window_height(self):
        return int(self.size[1]) if self.size else 480

    def title(self, titlestring):
        self.title_text = str(titlestring)

    def turtles(self):
        return list(self.turtles_list)

    def clear(self):
        self.items = []
        self.turtles_list = []
        self.bg = "#ffffff"
        self.timers = []

    clearscreen = clear

    def reset(self):
        for t in self.turtles_list:
            t.reset()

    resetscreen = reset

    def register_shape(self, name, shape=None):
        if shape is None:
            self.shapes[name] = ()
        elif isinstance(shape, (tuple, list)):
            self.shapes[name] = tuple(tuple(p) for p in shape)

    addshape = register_shape

    def getshapes(self):
        return sorted(self.shapes)

    def ontimer(self, fun, t=0):
        if callable(fun):
            self.timers.append(fun)

    def _run_timers(self):
        calls = 0
        while self.timers and calls < MAX_TIMER_CALLS:
            fun = self.timers.pop(0)
            calls += 1
            fun()

    def mainloop(self):
        self._run_timers()
        _show()

    done = mainloop

    def exitonclick(self):
        self.mainloop()

    def bye(self):
        _show()

    def textinput(self, title, prompt):
        try:
            return builtins.input(str(prompt) + " ")
        except EOFError:
            return None

    def numinput(self, title, prompt, default=None, minval=None, maxval=None):
        text = self.textinput(title, prompt)
        if text is None or text.strip() == "":
            return default
        value = float(text.replace(",", "."))
        if minval is not None and value < minval:
            value = minval
        if maxval is not None and value > maxval:
            value = maxval
        return value

    # Interactive features without a window: accepted and ignored.
    def _ignore(self, *args, **kwargs):
        return None

    tracer = delay = update = listen = onkey = onkeypress = onkeyrelease = onclick = onscreenclick = \
        onrelease = _ignore

    def getcanvas(self):
        raise TurtleGraphicsError("Im Programming Lab gibt es kein Turtle-Fenster (getcanvas)")

    def cv(self):  # pragma: no cover - only for compatibility
        return None


_screen = None


def Screen():
    global _screen
    if _screen is None:
        _screen = _Screen()
    return _screen


TurtleScreen = _Screen


# --------------------------------------------------------------------------
# Turtle
# --------------------------------------------------------------------------

class Turtle:
    """A turtle that records instead of drawing on a Tk canvas."""

    _pen = None

    def __init__(self, shape="classic", undobuffersize=1000, visible=True):
        self.screen = Screen()
        self.screen.turtles_list.append(self)
        self.shape_name = shape
        self._visible = visible
        self.reset()

    # -- state ----------------------------------------------------------

    def reset(self):
        self.x = 0.0
        self.y = 0.0
        self.angle = 90.0 if self.screen.mode_value == "logo" else 0.0  # math angle, degrees
        self.full = 360.0
        self.down = True
        self.width_value = 1.0
        self.pen_color = "#000000"
        self.fill_color = "#000000"
        self.fill_item = None
        self.poly = None
        self.stretch = (1.0, 1.0, 1.0)
        self.tilt_value = 0.0
        self.history = []
        self._remove_own_items()

    def _remove_own_items(self):
        self.screen.items = [i for i in self.screen.items if i.get("owner") is not self]

    def _snapshot(self, before):
        self.history.append((self.x, self.y, self.angle, before))
        if len(self.history) > 1000:
            self.history.pop(0)

    def _to_math(self, heading):
        """User heading (degrees or radians) -> internal math angle in degrees."""
        deg = heading * 360.0 / self.full
        return 90.0 - deg if self.screen.mode_value == "logo" else deg

    def _from_math(self, angle):
        deg = (90.0 - angle) if self.screen.mode_value == "logo" else angle
        return (deg % 360.0) * self.full / 360.0

    # -- movement -------------------------------------------------------

    def _line_to(self, x, y):
        before = len(self.screen.items)
        if self.down and (x, y) != (self.x, self.y):
            self.screen.add({"t": "line", "owner": self, "x1": self.x, "y1": self.y, "x2": x, "y2": y,
                             "color": self.pen_color, "width": self.width_value})
        if self.fill_item is not None:
            self.fill_item["points"].append((x, y))
        if self.poly is not None:
            self.poly.append((x, y))
        self._snapshot(before)
        self.x, self.y = float(x), float(y)

    def forward(self, distance):
        a = math.radians(self.angle)
        self._line_to(self.x + distance * math.cos(a), self.y + distance * math.sin(a))

    fd = forward

    def back(self, distance):
        self.forward(-distance)

    bk = backward = back

    def left(self, angle):
        self.angle = (self.angle + angle * 360.0 / self.full) % 360.0

    lt = left

    def right(self, angle):
        self.angle = (self.angle - angle * 360.0 / self.full) % 360.0

    rt = right

    def goto(self, x, y=None):
        if y is None:
            x, y = x
        self._line_to(float(x), float(y))

    setpos = setposition = goto

    def teleport(self, x=None, y=None, *, fill_gap=False):
        x = self.x if x is None else float(x)
        y = self.y if y is None else float(y)
        down = self.down
        self.down = False
        self._line_to(x, y)
        self.down = down

    def setx(self, x):
        self.goto(x, self.y)

    def sety(self, y):
        self.goto(self.x, y)

    def setheading(self, to_angle):
        self.angle = self._to_math(to_angle) % 360.0

    seth = setheading

    def home(self):
        self.goto(0, 0)
        self.setheading(0)

    def circle(self, radius, extent=None, steps=None):
        if extent is None:
            extent = self.full
        if steps is None:
            frac = abs(extent) / self.full
            steps = 1 + int(min(11 + abs(radius) / 6.0, 59.0) * frac)
        steps = max(1, int(steps))
        w = 1.0 * extent / steps
        w2 = 0.5 * w
        length = 2.0 * radius * math.sin(math.radians(w2 * 360.0 / self.full))
        if radius < 0:
            length, w, w2 = -length, -w, -w2
        self.left(w2)
        for _ in range(steps):
            self.forward(length)
            self.left(w)
        self.left(-w2)

    def dot(self, size=None, *color):
        if size is not None and not isinstance(size, (int, float)):
            color = (size,) + color
            size = None
        if size is None:
            size = max(self.width_value + 4, self.width_value * 2)
        col = self.pen_color
        if color:
            col = normalise_color(color[0] if len(color) == 1 else color, self.screen.colormode_value)
        self._snapshot(len(self.screen.items))
        self.screen.add({"t": "dot", "owner": self, "x": self.x, "y": self.y, "size": float(size), "color": col})

    def stamp(self):
        self._snapshot(len(self.screen.items))
        item = self.screen.add({"t": "stamp", "owner": self, "x": self.x, "y": self.y, "angle": self.angle,
                                "shape": self.shape_name, "color": self.pen_color, "fill": self.fill_color,
                                "stretch": self.stretch})
        return id(item)

    def clearstamp(self, stampid):
        self.screen.items = [i for i in self.screen.items if not (i.get("t") == "stamp" and id(i) == stampid)]

    def clearstamps(self, n=None):
        stamps = [i for i in self.screen.items if i.get("t") == "stamp" and i.get("owner") is self]
        if n is None:
            remove = stamps
        elif n >= 0:
            remove = stamps[:n]
        else:
            remove = stamps[n:]
        ids = {id(i) for i in remove}
        self.screen.items = [i for i in self.screen.items if id(i) not in ids]

    def undo(self):
        if not self.history:
            return
        x, y, angle, before = self.history.pop()
        del self.screen.items[before:]
        self.x, self.y, self.angle = x, y, angle

    def undobufferentries(self):
        return len(self.history)

    def setundobuffer(self, size):
        return None

    # -- state queries --------------------------------------------------

    def position(self):
        return Vec2D(self.x, self.y)

    pos = position

    def xcor(self):
        return self.x

    def ycor(self):
        return self.y

    def heading(self):
        return self._from_math(self.angle)

    def towards(self, x, y=None):
        if y is None:
            x, y = x
        angle = math.degrees(math.atan2(y - self.y, x - self.x))
        return self._from_math(angle)

    def distance(self, x, y=None):
        if y is None:
            if isinstance(x, Turtle):
                x, y = x.x, x.y
            else:
                x, y = x
        return math.hypot(x - self.x, y - self.y)

    def degrees(self, fullcircle=360.0):
        self.full = float(fullcircle)

    def radians(self):
        self.full = 2 * math.pi

    # -- pen ------------------------------------------------------------

    def pendown(self):
        self.down = True

    pd = down = pendown

    def penup(self):
        self.down = False

    pu = up = penup

    def isdown(self):
        return self.down

    def pensize(self, width=None):
        if width is None:
            return self.width_value
        self.width_value = float(width)

    width = pensize

    def speed(self, speed=None):
        return 0 if speed is None else None

    def _col(self, args):
        return normalise_color(args[0] if len(args) == 1 else args, self.screen.colormode_value)

    def pencolor(self, *args):
        if not args:
            return self.pen_color
        self.pen_color = self._col(args)

    def fillcolor(self, *args):
        if not args:
            return self.fill_color
        self.fill_color = self._col(args)
        if self.fill_item is not None:
            self.fill_item["color"] = self.fill_color

    def color(self, *args):
        if not args:
            return self.pen_color, self.fill_color
        if len(args) == 1:
            self.pen_color = self.fill_color = self._col(args)
        elif len(args) == 2:
            self.pen_color = self._col((args[0],))
            self.fill_color = self._col((args[1],))
        elif len(args) == 3:
            self.pen_color = self.fill_color = self._col(args)
        else:
            raise TurtleGraphicsError("color() takes up to 3 arguments")
        if self.fill_item is not None:
            self.fill_item["color"] = self.fill_color

    def pen(self, pen=None, **kwargs):
        state = {"shown": self._visible, "pendown": self.down, "pencolor": self.pen_color,
                 "fillcolor": self.fill_color, "pensize": self.width_value, "speed": 0, "resizemode": "noresize",
                 "stretchfactor": self.stretch[:2], "outline": self.stretch[2], "tilt": self.tilt_value}
        if pen is None and not kwargs:
            return state
        settings = dict(pen or {})
        settings.update(kwargs)
        if "shown" in settings:
            self._visible = bool(settings["shown"])
        if "pendown" in settings:
            self.down = bool(settings["pendown"])
        if "pencolor" in settings:
            self.pencolor(settings["pencolor"])
        if "fillcolor" in settings:
            self.fillcolor(settings["fillcolor"])
        if "pensize" in settings:
            self.pensize(settings["pensize"])

    def filling(self):
        return self.fill_item is not None

    def begin_fill(self):
        if self.fill_item is not None:
            self.end_fill()
        # Added now so that the filled area lies below what is drawn while filling (as in Tk).
        self.fill_item = self.screen.add({"t": "fill", "owner": self, "points": [(self.x, self.y)],
                                          "color": self.fill_color}) or {"points": []}

    def end_fill(self):
        item = self.fill_item
        self.fill_item = None
        if item is not None and len(item["points"]) < 3 and item in self.screen.items:
            self.screen.items.remove(item)

    def begin_poly(self):
        self.poly = [(self.x, self.y)]

    def end_poly(self):
        self._last_poly = tuple(self.poly or ())
        self.poly = None

    def get_poly(self):
        return getattr(self, "_last_poly", None)

    def write(self, arg, move=False, align="left", font=("Arial", 8, "normal")):
        text = str(arg)
        size = 8
        try:
            size = float(font[1])
        except Exception:
            pass
        self._snapshot(len(self.screen.items))
        self.screen.add({"t": "text", "owner": self, "x": self.x, "y": self.y, "text": text, "color": self.pen_color,
                         "align": align if align in ("left", "center", "right") else "left", "size": size,
                         "font": font})
        if move:
            down = self.down
            self.down = False
            self.goto(self.x + len(text) * size * 0.6, self.y)
            self.down = down

    def clear(self):
        self._remove_own_items()

    # -- appearance -----------------------------------------------------

    def showturtle(self):
        self._visible = True

    st = showturtle

    def hideturtle(self):
        self._visible = False

    ht = hideturtle

    def isvisible(self):
        return self._visible

    def shape(self, name=None):
        if name is None:
            return self.shape_name
        if name not in self.screen.shapes:
            raise TurtleGraphicsError("There is no shape named %s" % name)
        self.shape_name = name

    def shapesize(self, stretch_wid=None, stretch_len=None, outline=None):
        if stretch_wid is None and stretch_len is None and outline is None:
            return self.stretch
        wid = self.stretch[0] if stretch_wid is None else float(stretch_wid)
        length = wid if stretch_len is None and stretch_wid is not None else (
            self.stretch[1] if stretch_len is None else float(stretch_len))
        self.stretch = (wid, length, self.stretch[2] if outline is None else float(outline))

    turtlesize = shapesize

    def resizemode(self, rmode=None):
        return "noresize" if rmode is None else None

    def settiltangle(self, angle):
        self.tilt_value = float(angle)

    def tiltangle(self, angle=None):
        if angle is None:
            return self.tilt_value
        self.tilt_value = float(angle)

    def tilt(self, angle):
        self.tilt_value += float(angle)

    def shearfactor(self, shear=None):
        return 0.0 if shear is None else None

    def shapetransform(self, *args):
        return None

    def get_shapepoly(self):
        return self.screen.shapes.get(self.shape_name)

    # -- misc -----------------------------------------------------------

    def clone(self):
        other = Turtle.__new__(Turtle)
        other.__dict__.update(self.__dict__)
        other.history = []
        other.fill_item = None
        self.screen.turtles_list.append(other)
        return other

    def getturtle(self):
        return self

    getpen = getturtle

    def getscreen(self):
        return self.screen

    def onclick(self, *args, **kwargs):
        return None

    onrelease = ondrag = onclick

    def __repr__(self):
        return "<turtle.Turtle object>"


RawTurtle = RawPen = Pen = Turtle


def _getpen():
    if Turtle._pen is None:
        Turtle._pen = Turtle()
    return Turtle._pen


def getturtle():
    return _getpen()


getpen = getturtle


# --------------------------------------------------------------------------
# Rendering and export (used by the harness)
# --------------------------------------------------------------------------

def used():
    return _screen is not None and (bool(_screen.items) or bool(_screen.turtles_list))


def _shape_polygon(item_or_turtle):
    get = item_or_turtle.get if isinstance(item_or_turtle, dict) else (lambda k, d=None: getattr(item_or_turtle, k, d))
    shape = _screen.shapes.get(get("shape") or "classic", SHAPES["classic"])
    if not shape:
        return None
    angle = math.radians((get("angle") or 0.0) - 90.0)
    c, s = math.cos(angle), math.sin(angle)
    sw, sl = (get("stretch") or (1.0, 1.0, 1.0))[:2]
    pts = []
    for px, py in shape:
        px, py = px * sw, py * sl
        pts.append((get("x") + px * c - py * s, get("y") + px * s + py * c))
    return pts


def _bounds(items, turtles):
    xs, ys = [], []
    for i in items:
        if i["t"] == "line":
            xs += [i["x1"], i["x2"]]
            ys += [i["y1"], i["y2"]]
        elif i["t"] == "fill":
            xs += [p[0] for p in i["points"]]
            ys += [p[1] for p in i["points"]]
        elif i["t"] in ("dot", "text", "stamp"):
            r = i.get("size", 10) / 2.0 + (10 if i["t"] == "stamp" else 0)
            xs += [i["x"] - r, i["x"] + r]
            ys += [i["y"] - r, i["y"] + r]
            if i["t"] == "text":
                width = len(i["text"]) * i["size"] * 0.75
                xs += [i["x"] + width, i["x"] - width]
                ys += [i["y"] + i["size"] * 1.5]
    for t in turtles:
        if t._visible:
            xs += [t.x - 12, t.x + 12]
            ys += [t.y - 12, t.y + 12]
    if not xs:
        return -100.0, -100.0, 100.0, 100.0
    return min(xs), min(ys), max(xs), max(ys)


def render_png():
    """Render the current drawing, return base64 PNG (or None)."""
    if _screen is None:
        return None
    try:
        from matplotlib.figure import Figure
        from matplotlib.backends.backend_agg import FigureCanvasAgg
        from matplotlib.patches import Polygon, Circle
        from matplotlib.collections import LineCollection
    except Exception:  # pragma: no cover
        return None
    items = list(_screen.items)
    turtles = [t for t in _screen.turtles_list if t._visible]
    if _screen.world:
        x0, y0, x1, y1 = _screen.world
    else:
        x0, y0, x1, y1 = _bounds(items, turtles)
        pad = max(10.0, 0.06 * max(x1 - x0, y1 - y0))
        x0, y0, x1, y1 = x0 - pad, y0 - pad, x1 + pad, y1 + pad
        # Small drawings are not blown up beyond twice their size.
        minside = 120.0
        if x1 - x0 < minside:
            c = (x0 + x1) / 2
            x0, x1 = c - minside / 2, c + minside / 2
        if y1 - y0 < minside:
            c = (y0 + y1) / 2
            y0, y1 = c - minside / 2, c + minside / 2
    w, h = max(1e-6, x1 - x0), max(1e-6, y1 - y0)
    scale = min(560.0 / w, 480.0 / h, 2.0)   # pixels per turtle unit
    px_w, px_h = max(80, int(w * scale)), max(80, int(h * scale))
    dpi = 72
    fig = Figure(figsize=(px_w / dpi, px_h / dpi), dpi=dpi)
    FigureCanvasAgg(fig)
    ax = fig.add_axes((0, 0, 1, 1))
    ax.set_xlim(x0, x1)
    ax.set_ylim(y0, y1)
    ax.set_aspect("equal", adjustable="box")
    ax.axis("off")
    fig.patch.set_facecolor(_screen.bg or "#ffffff")
    pt = scale * 72.0 / dpi  # points per turtle unit

    def safe(color, fallback="#000000"):
        return color if color and color.startswith("#") else fallback

    z = 1
    segs, cols, widths = [], [], []

    def flush():
        nonlocal segs, cols, widths, z
        if segs:
            ax.add_collection(LineCollection(segs, colors=cols, linewidths=widths, capstyle="round",
                                             joinstyle="round", zorder=z))
            z += 1
            segs, cols, widths = [], [], []

    for i in items:
        t = i["t"]
        if t == "line":
            segs.append(((i["x1"], i["y1"]), (i["x2"], i["y2"])))
            cols.append(safe(i["color"]))
            widths.append(max(0.5, i["width"] * pt))
            continue
        flush()
        if t == "fill" and len(i["points"]) >= 3:
            ax.add_patch(Polygon(i["points"], closed=True, facecolor=safe(i["color"]), edgecolor="none", zorder=z))
        elif t == "dot":
            ax.add_patch(Circle((i["x"], i["y"]), i["size"] / 2.0, facecolor=safe(i["color"]), edgecolor="none", zorder=z))
        elif t == "text":
            ha = {"left": "left", "center": "center", "right": "right"}[i["align"]]
            ax.text(i["x"], i["y"], i["text"], color=safe(i["color"]), fontsize=max(4.0, i["size"] * pt),
                    ha=ha, va="bottom", zorder=z)
        elif t == "stamp":
            poly = _shape_polygon(i)
            if poly:
                ax.add_patch(Polygon(poly, closed=True, facecolor=safe(i["fill"]), edgecolor=safe(i["color"]),
                                     linewidth=max(0.5, pt), zorder=z))
        z += 1
    flush()
    for t in turtles:
        poly = _shape_polygon(t)
        if poly:
            ax.add_patch(Polygon(poly, closed=True, facecolor=safe(t.fill_color), edgecolor=safe(t.pen_color),
                                 linewidth=max(0.5, pt), zorder=z + 1))
    buf = io.BytesIO()
    fig.savefig(buf, format="png", dpi=dpi, facecolor=fig.get_facecolor())
    png = base64.b64encode(buf.getvalue()).decode("ascii")
    return png if len(png) <= 600000 else None


def export(with_png=True):
    """Everything a test compares, plus the picture."""
    if _screen is None:
        return {"used": False, "segments": [], "fills": [], "dots": [], "texts": [], "png": None}
    r = lambda v: round(float(v), 3)  # noqa: E731
    segments, fills, dots, texts = [], [], [], []
    for i in _screen.items:
        t = i["t"]
        if t == "line":
            segments.append([r(i["x1"]), r(i["y1"]), r(i["x2"]), r(i["y2"]), i["color"], r(i["width"])])
        elif t == "fill" and len(i["points"]) >= 3:
            fills.append({"points": [[r(x), r(y)] for x, y in i["points"]][:5000], "color": i["color"]})
        elif t == "dot":
            dots.append([r(i["x"]), r(i["y"]), r(i["size"]), i["color"]])
        elif t == "text":
            texts.append([r(i["x"]), r(i["y"]), i["text"][:500], i["color"]])
    return {"used": True, "segments": segments, "fills": fills, "dots": dots, "texts": texts,
            "bgcolor": _screen.bg, "truncated": _screen.truncated,
            "png": render_png() if with_png else None}


# Set by the harness: called with a PNG (base64) whenever the drawing is "shown".
on_show = None


def _show():
    if _screen is None or on_show is None:
        return
    if _screen.shown_items == len(_screen.items) and _screen.items:
        return
    _screen.shown_items = len(_screen.items)
    png = render_png()
    if png:
        on_show(png)


def show_if_pending():
    """End of the program: show the drawing unless it was just shown."""
    if used() and _screen.shown_items != len(_screen.items):
        _show()


# --------------------------------------------------------------------------
# Module-level functions (procedural interface, like the standard library)
# --------------------------------------------------------------------------

_TURTLE_METHODS = [
    "forward", "fd", "back", "bk", "backward", "left", "lt", "right", "rt", "goto", "setpos", "setposition",
    "teleport", "setx", "sety", "setheading", "seth", "home", "circle", "dot", "stamp", "clearstamp", "clearstamps",
    "undo", "undobufferentries", "setundobuffer", "position", "pos", "xcor", "ycor", "heading", "towards",
    "distance", "degrees", "radians", "pendown", "pd", "down", "penup", "pu", "up", "isdown", "pensize", "width",
    "speed", "pencolor", "fillcolor", "color", "pen", "filling", "begin_fill", "end_fill", "begin_poly",
    "end_poly", "get_poly", "write", "showturtle", "st", "hideturtle", "ht", "isvisible", "shape", "shapesize",
    "turtlesize", "resizemode", "settiltangle", "tiltangle", "tilt", "shearfactor", "shapetransform", "get_shapepoly",
    "clone", "getscreen", "onclick", "onrelease", "ondrag", "reset",
]
_SCREEN_METHODS = [
    "bgcolor", "bgpic", "colormode", "mode", "setworldcoordinates", "setup", "screensize", "window_width",
    "window_height", "title", "turtles", "clearscreen", "resetscreen", "register_shape", "addshape", "getshapes",
    "ontimer", "mainloop", "done", "exitonclick", "bye", "textinput", "numinput", "tracer", "delay", "update",
    "listen", "onkey", "onkeypress", "onkeyrelease", "onscreenclick", "getcanvas",
]


def _make_turtle_function(name):
    def fn(*args, **kwargs):
        return getattr(_getpen(), name)(*args, **kwargs)
    fn.__name__ = name
    return fn


def _make_screen_function(name):
    def fn(*args, **kwargs):
        return getattr(Screen(), name)(*args, **kwargs)
    fn.__name__ = name
    return fn


for _name in _TURTLE_METHODS:
    globals()[_name] = _make_turtle_function(_name)
for _name in _SCREEN_METHODS:
    globals()[_name] = _make_screen_function(_name)


def clear():
    """Delete the drawings of the default turtle (module-level clear())."""
    _getpen().clear()


__all__ = (["Turtle", "RawTurtle", "Pen", "RawPen", "Screen", "TurtleScreen", "Vec2D", "Terminator",
            "TurtleGraphicsError", "getturtle", "getpen", "clear"] + _TURTLE_METHODS + _SCREEN_METHODS)
