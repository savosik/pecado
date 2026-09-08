# -*- coding: utf-8 -*-
"""Генератор BPMN 2.0 (XML + SVG) из единого описания узлов и связей."""
import html, textwrap

TASK_W, TASK_H = 230, 76
GW = 50
EV = 36

class Diagram:
    def __init__(self, pid, name):
        self.pid, self.name = pid, name
        self.nodes = {}   # id -> dict(type,label,x,y,w,h)
        self.edges = []   # dict(id,src,tgt,label,pts)

    def node(self, nid, kind, label, x, y):
        w, h = (TASK_W, TASK_H) if kind == "task" else ((GW, GW) if kind == "gw" else (EV, EV))
        self.nodes[nid] = dict(kind=kind, label=label, x=x, y=y, w=w, h=h)
        return nid

    def edge(self, src, tgt, label="", pts=None):
        eid = "f_%d" % (len(self.edges) + 1)
        self.edges.append(dict(id=eid, src=src, tgt=tgt, label=label, pts=pts or self.auto(src, tgt)))
        return eid

    def c(self, nid):
        n = self.nodes[nid]
        return n["x"] + n["w"] / 2, n["y"] + n["h"] / 2

    def auto(self, src, tgt):
        a, b = self.nodes[src], self.nodes[tgt]
        ax, ay = self.c(src); bx, by = self.c(tgt)
        if abs(ax - bx) < 3:                       # строго вниз
            return [(ax, a["y"] + a["h"]), (bx, b["y"])]
        if by - ay > 40 and ax < bx:               # вправо-вниз
            return [(a["x"] + a["w"], ay), (bx, ay), (bx, b["y"])]
        if ax < bx:                                # вправо
            return [(a["x"] + a["w"], ay), (b["x"], by)]
        return [(a["x"], ay), (b["x"] + b["w"], by)]  # влево

    # ── SVG ────────────────────────────────────────────────────────────────
    def svg(self, title, subtitle):
        maxx = max(n["x"] + n["w"] for n in self.nodes.values()) + 60
        maxy = max(n["y"] + n["h"] for n in self.nodes.values()) + 60
        for e in self.edges:
            for x, y in e["pts"]:
                maxx = max(maxx, x + 40); maxy = max(maxy, y + 40)
        o = ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" font-family="IBM Plex Sans, Arial, sans-serif">' % (maxx, maxy)]
        o.append('<defs><marker id="a" markerWidth="9" markerHeight="9" refX="8" refY="4.5" orient="auto">'
                 '<path d="M0,0 L9,4.5 L0,9 z" fill="#1B2620"/></marker></defs>')
        o.append('<rect width="%d" height="%d" fill="#FFFFFF"/>' % (maxx, maxy))
        o.append('<text x="40" y="46" font-size="24" font-weight="700" fill="#1B2620">%s</text>' % html.escape(title))
        o.append('<text x="40" y="70" font-size="13" fill="#5A6862">%s</text>' % html.escape(subtitle))
        for e in self.edges:
            pts = " ".join("%.0f,%.0f" % p for p in e["pts"])
            o.append('<polyline points="%s" fill="none" stroke="#1B2620" stroke-width="1.6" marker-end="url(#a)"/>' % pts)
            if e["label"]:
                (x0, y0), (x1, y1) = e["pts"][0], e["pts"][1]
                if abs(y1 - y0) < 3:                    # ветвь идёт вбок
                    lx, ly, anch = x0 + 12, y0 - 9, "start"
                else:                                   # ветвь идёт вниз
                    lx, ly, anch = x0 + 10, y0 + 20, "start"
                o.append('<text x="%.0f" y="%.0f" font-size="12" font-weight="600" text-anchor="%s" fill="#1F6F50">%s</text>'
                         % (lx, ly, anch, html.escape(e["label"])))
        for nid, n in self.nodes.items():
            x, y, w, h = n["x"], n["y"], n["w"], n["h"]
            if n["kind"] == "task":
                o.append('<rect x="%d" y="%d" width="%d" height="%d" rx="9" fill="#FFFFFF" stroke="#1B2620" stroke-width="1.6"/>' % (x, y, w, h))
                lines = textwrap.wrap(n["label"], 30)[:4]
                ty = y + h / 2 - (len(lines) - 1) * 8
                for ln in lines:
                    o.append('<text x="%d" y="%.0f" font-size="12.5" text-anchor="middle" fill="#1B2620">%s</text>'
                             % (x + w / 2, ty + 4, html.escape(ln)))
                    ty += 16
            elif n["kind"] == "gw":
                cx, cy = x + w / 2, y + h / 2
                o.append('<polygon points="%d,%d %d,%d %d,%d %d,%d" fill="#FFFFFF" stroke="#1B2620" stroke-width="1.6"/>'
                         % (cx, y, x + w, cy, cx, y + h, x, cy))
                o.append('<text x="%d" y="%d" font-size="17" text-anchor="middle" fill="#1B2620">&#215;</text>' % (cx, cy + 6))
                lines = textwrap.wrap(n["label"], 22)[:3]
                ty = cy - (len(lines) - 1) * 7.5
                for ln in lines:
                    o.append('<text x="%d" y="%.0f" font-size="12" font-weight="600" text-anchor="end" fill="#1B2620">%s</text>'
                             % (x - 14, ty + 4, html.escape(ln)))
                    ty += 15
            else:
                cx, cy = x + w / 2, y + h / 2
                sw = 2 if n["kind"] == "start" else 4
                o.append('<circle cx="%d" cy="%d" r="%d" fill="#FFFFFF" stroke="#1B2620" stroke-width="%d"/>' % (cx, cy, w / 2, sw))
                lines = textwrap.wrap(n["label"], 22)[:2]
                ty = cy + h / 2 + 16
                for ln in lines:
                    o.append('<text x="%d" y="%.0f" font-size="12" text-anchor="middle" fill="#1B2620">%s</text>' % (cx, ty, html.escape(ln)))
                    ty += 14
        o.append("</svg>")
        return "\n".join(o)

    # ── BPMN 2.0 XML ───────────────────────────────────────────────────────
    def bpmn(self):
        el, di = [], []
        kindmap = {"start": "startEvent", "end": "endEvent", "task": "task", "gw": "exclusiveGateway"}
        for nid, n in self.nodes.items():
            tag = kindmap[n["kind"]]
            inc = "".join("<bpmn:incoming>%s</bpmn:incoming>" % e["id"] for e in self.edges if e["tgt"] == nid)
            out = "".join("<bpmn:outgoing>%s</bpmn:outgoing>" % e["id"] for e in self.edges if e["src"] == nid)
            el.append('<bpmn:%s id="%s" name="%s">%s%s</bpmn:%s>' % (tag, nid, html.escape(n["label"]), inc, out, tag))
            di.append('<bpmndi:BPMNShape id="%s_di" bpmnElement="%s"><dc:Bounds x="%d" y="%d" width="%d" height="%d"/></bpmndi:BPMNShape>'
                      % (nid, nid, n["x"], n["y"], n["w"], n["h"]))
        for e in self.edges:
            el.append('<bpmn:sequenceFlow id="%s" name="%s" sourceRef="%s" targetRef="%s"/>'
                      % (e["id"], html.escape(e["label"]), e["src"], e["tgt"]))
            wp = "".join('<di:waypoint x="%.0f" y="%.0f"/>' % p for p in e["pts"])
            di.append('<bpmndi:BPMNEdge id="%s_di" bpmnElement="%s">%s</bpmndi:BPMNEdge>' % (e["id"], e["id"], wp))
        return ('<?xml version="1.0" encoding="UTF-8"?>\n'
                '<bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL" '
                'xmlns:bpmndi="http://www.omg.org/spec/BPMN/20100524/DI" '
                'xmlns:dc="http://www.omg.org/spec/DD/20100524/DC" '
                'xmlns:di="http://www.omg.org/spec/DD/20100524/DI" '
                'id="def_%s" targetNamespace="http://pecado.ru/bpmn">\n'
                '<bpmn:process id="%s" name="%s" isExecutable="false">\n%s\n</bpmn:process>\n'
                '<bpmndi:BPMNDiagram id="dia_%s"><bpmndi:BPMNPlane id="plane_%s" bpmnElement="%s">\n%s\n'
                '</bpmndi:BPMNPlane></bpmndi:BPMNDiagram>\n</bpmn:definitions>\n'
                % (self.pid, self.pid, html.escape(self.name), "\n".join(el), self.pid, self.pid, self.pid, "\n".join(di)))
