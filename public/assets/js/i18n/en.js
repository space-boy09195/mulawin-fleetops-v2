/* assets/js/i18n/en.js — English para sa ilang tekstong Tagalog sa source (kapag English ang napili). */
'use strict';
I18n.add('en', {
"Gastos at Bayarin": "Expenses & Payables",
"Empleyado": "Employees",
"Plano": "Planning",
"Paano gamitin:": "How to use:",
"Palitan ang numero at pindutin ang Save. Lahat ng pagbabago ay nakatala sa Activity Log. Ang \"Reset\" ay ibabalik sa default na nasa": "Change the number and press Save. Every change is recorded in the Activity Log. \"Reset\" goes back to the default in",
"Kung ang purchase request ay HANGGANG dito, Finance ang mag-a-approve. Kung LAMPAS, Admin ang mag-a-approve.": "If the purchase request is UP TO this amount, Finance approves it. If it is ABOVE, the Admin approves it.",
"Ilang km ang natatakbo ng truck sa 1 litro. Ginagamit sa fuel computation: (km ÷ ito) × presyo ng diesel.": "How many km the truck runs on 1 liter. Used in the fuel computation: (km ÷ this) × diesel price.",
"Presyo ng diesel kada litro. Palitan kapag nagbago ang presyo.": "Diesel price per liter. Change it when the price changes.",
"Unang laman ng \"units\" kapag gumawa ng car carrier trip. Puwedeng palitan sa mismong trip.": "First value of \"units\" when a car carrier trip is created. It can be changed on the trip itself.",
"Kapag umabot sa ganitong km ang natakbo ng truck mula sa huling preventive maintenance, ise-set ng system ang truck for preventive maintenance.": "When the truck has run this many km since the last preventive maintenance, the system sets the truck for preventive maintenance.",
"1 = pagdating ng truck at umabot na sa km limit, gagawin ng system na \"Under Maintenance\" at gagawa ng preventive job. 0 = gagawa lang ng job at reminder pero mananatiling Available ang truck.": "1 = when the truck arrives and has reached the km limit, the system sets it \"Under Maintenance\" and makes a preventive job. 0 = the system only makes the job and a reminder, and the truck stays Available.",
"Pinakamaikling oras mula pagpindot ng \"Start checklist\" hanggang ma-submit. Ilagay 0 para i-off.": "Shortest time from pressing \"Start checklist\" until it is submitted. Put 0 to turn it off.",
"Ilang araw bago mag-expire ang OR/CR, insurance, kontrata at ibang dokumento ay lalabas na ang paalala. Formula: Expiry date − araw ngayon.": "How many days before the OR/CR, insurance, contracts and other documents expire the reminder appears. Formula: Expiry date − today.",
"Ilang araw bago mag-expire ang lisensya ng driver ay lalabas na ang paalala.": "How many days before a driver's license expires the reminder appears.",
"Ginagamit lang kung walang payment terms ang client. Due date = invoice date + ito.": "Used only when the client has no payment terms. Due date = invoice date + this.",
"1 = ang biyahe na walang naka-record na waybill ay hindi mabibilang sa trip pay (lalabas sa listahan ng kulang na waybill).": "1 = a trip with no recorded waybill is not counted in trip pay (it appears in the list of missing waybills).",
"Sa auto-assign, uunahin ang truck at driver na may pinakakaunting biyahe sa nakaraang ganitong bilang ng araw.": "In auto-assign, the truck and driver with the fewest trips in this many past days come first."
});
