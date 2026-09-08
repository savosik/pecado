# -*- coding: utf-8 -*-
import sys
sys.path.insert(0, "/tmp/claude-1000/-home-savosik-projects-pecado/3eb47bfb-18f4-46f3-8e14-63853544449e/scratchpad/bpmn")
from gen import Diagram, TASK_W, TASK_H, GW, EV

MAIN = 470                      # левый край основной колонки
BR   = 640                      # левый край колонки ветвлений
mc   = MAIN + TASK_W / 2        # центр основной колонки
gx   = mc - GW / 2              # x шлюза на основной оси
ex   = mc - EV / 2              # x события на основной оси

# ══════════════ ДИАГРАММА 1: РАБОЧИЙ ЦИКЛ МЕНЕДЖЕРА ══════════════
d = Diagram("manager_cycle", "Рабочий цикл менеджера отдела продаж")
d.node("s",   "start", "Начало рабочего дня",                                   ex, 110)
d.node("t1",  "task",  "Открыть «Мой месяц»: доход, план, сколько до начала оплаты", MAIN, 200)
d.node("g1",  "gw",    "Оплата за базу уже началась?",                          gx, 330)
d.node("t2",  "task",  "Дожать до начала оплаты: клиенты с наибольшим разрывом", BR, 315)
d.node("t3",  "task",  "Наращивать сверх планки: каждый рубль даёт 1,9 копейки", MAIN, 440)
d.node("m1",  "gw",    "",                                                      gx, 560)
d.node("t4",  "task",  "Отработать обещания оплаты, назначенные на сегодня",    MAIN, 640)
d.node("g2",  "gw",    "Есть долг старше 15 дней без движения?",                gx, 770)
d.node("t5",  "task",  "Передать долг руководителю с историей контактов",       BR, 755)
d.node("m2",  "gw",    "",                                                      gx, 890)
d.node("t6",  "task",  "Пять и более результативных контактов по списку A2",    MAIN, 960)
d.node("t7",  "task",  "Записать контакты, обещания и задачи в CRM",            MAIN, 1080)
d.node("g3",  "gw",    "Сегодня понедельник?",                                  gx, 1210)
d.node("t8",  "task",  "Недельный блок: забытые клиенты, новые, фокус-товары, сроки по пулу", BR, 1195)
d.node("m3",  "gw",    "",                                                      gx, 1330)
d.node("g4",  "gw",    "Какое сегодня число месяца?",                           gx, 1420)
d.node("t9",  "task",  "1–5: сверить расчётный лист, при расхождении — возражение", BR, 1360)
d.node("t10", "task",  "25–31: дожим по списку и контроль оформления заказов",  BR, 1480)
d.node("m4",  "gw",    "",                                                      gx, 1580)
d.node("e",   "end",   "День закрыт",                                           ex, 1670)

d.edge("s", "t1"); d.edge("t1", "g1")
d.edge("g1", "t2", "нет")
d.edge("g1", "t3", "да")
d.edge("t3", "m1")
d.edge("t2", "m1", "", [(BR + TASK_W / 2, 315 + TASK_H), (BR + TASK_W / 2, 585), (gx + GW, 585)])
d.edge("m1", "t4"); d.edge("t4", "g2")
d.edge("g2", "t5", "да")
d.edge("g2", "m2", "нет")
d.edge("t5", "m2", "", [(BR + TASK_W / 2, 755 + TASK_H), (BR + TASK_W / 2, 915), (gx + GW, 915)])
d.edge("m2", "t6"); d.edge("t6", "t7"); d.edge("t7", "g3")
d.edge("g3", "t8", "да")
d.edge("g3", "m3", "нет")
d.edge("t8", "m3", "", [(BR + TASK_W / 2, 1195 + TASK_H), (BR + TASK_W / 2, 1355), (gx + GW, 1355)])
d.edge("m3", "g4")
d.edge("g4", "t9", "1–5", [(gx + GW, 1445), (BR - 30, 1445), (BR - 30, 1398), (BR, 1398)])
d.edge("g4", "t10", "25–31", [(gx + GW, 1445), (BR - 30, 1445), (BR - 30, 1518), (BR, 1518)])
d.edge("g4", "m4", "прочие")
d.edge("t9", "m4", "", [(BR + TASK_W / 2, 1360), (BR + TASK_W + 60, 1360), (BR + TASK_W + 60, 1605), (gx + GW, 1605)])
d.edge("t10", "m4", "", [(BR + TASK_W / 2, 1480 + TASK_H), (BR + TASK_W / 2, 1605), (gx + GW, 1605)])
d.edge("m4", "e")

open("/home/savosik/projects/pecado/docs/sales/bpmn/rabochiy-cikl-menedzhera.svg", "w", encoding="utf-8").write(
    d.svg("Рабочий цикл менеджера отдела продаж",
          "Ежедневный цикл с недельными и месячными ветвлениями. Формы A1–A7 — см. «Формы отчётов и расчётов»"))
open("/home/savosik/projects/pecado/docs/sales/bpmn/rabochiy-cikl-menedzhera.bpmn", "w", encoding="utf-8").write(d.bpmn())
print("диаграмма 1 готова")
