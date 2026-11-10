# Gear and Currency

Gear is what a Character can carry, and Currency is what buys it. Two sections of Studio hold them: **Gear** for the catalog, and the Sheet for the fields that hold the pack and the money.

## The Gear catalog

The Gear catalog is a list of entries. Each has:

- a **key** and a **name**,
- a **cost**, in the unit of the Currency,
- **slots**, how much room one unit takes, from 0 to 100, and 1 when you leave it empty,
- an optional **category**, a short label,
- an optional **description**.

Studio's **Add gear** opens the form for one. Names must be unique, and the key follows the same rule as every key: lower case letters, digits and hyphens.

Hard Country's catalog has thirteen entries in two categories, Kit and Arms: rope, a lantern, lamp oil, a staff, a blanket, bandages, a waterskin, trail rations, carrying poles, a knife, a club, a sling and a pole axe. Most cost a few pennies and take one slot. Dogwatch has eleven, in Tools and Supplies: a multitool, a cutting torch, a patch kit, a pry bar, a handlight, a tether, a scanner, an air canister, ration bars, a med patch and a flare.

Write descriptions for the table, not the shop. "Fifty feet of hemp, to lash a load or pass a line across water" tells a player what the rope is for. Say what an item does not do when a player would assume it: the Hard Country bandages "close a cut; they do not mend a Wound", and the Dogwatch med patch "does not mend an Injury".

## Load

An **Inventory** is a sheet field that holds Gear. Hard Country's Load has a capacity of 8 slots and Dogwatch's Kit has 6. The capacity is the sum of slots, not a count of items.

That is the load rule, and the catalog is where you tune it.

- Most things take one slot. The pole axe takes two, and so do the carrying poles. In Dogwatch the cutting torch takes two, "loud, hot and bright", and the heavy tool costs room.
- Some things take none. Hard Country's lamp oil and Dogwatch's ration bars have 0 slots, so a bearer is never choosing between a day's food and a rope.
- An Inventory can also set a maximum number of stacks and the categories it accepts. Neither preset uses either, so each accepts every entry.

Count the slots of a plain starting kit before you fix the capacity. If a bearer carrying everything they would want needs 11 slots, a capacity of 8 makes a choice. Make sure that is the choice you wanted them to face.

A free stack, written by the player with a name, quantity and slots of its own, is also allowed. A shop never buys it.

An Inventory is not a sheet effect target and no Formula reads it. A rule such as "a bearer carrying more than six slots is slowed" cannot be a step condition. Write it in a tip, as the harm rule is.

## Currency

Currency is one Number field of the sheet, named in the Sheet's Currency panel with an optional unit. Hard Country's is `pennies`, with the unit "pennies". Dogwatch's is `chits`. Pennies have the dice default `2d6*10`, so the bearers begin with a purse. Chits have a plain default of 20.

The field's minimum is the Currency's floor, and 0 if the field sets none. A shop purchase or a voluntary sheet effect that would take the field under its floor is refused as insufficient funds. Both presets set the minimum to 0, so a Character cannot buy what they cannot pay for.

Costs are in the unit of the Currency, so a Gear entry of cost 8 is eight pennies in Hard Country and eight chits in Dogwatch. The two prices are not comparable, and need not be.

## Shops

A `shop` step offers Gear from the catalog: entries by key, or whole categories, optionally a random stock rolled when the step is reached, and a rate at which it buys things back. A purchase is all or nothing. Neither The Long Carry nor Cold Berth has a shop step: bearers begin with their purse and their load, and the crew with their kit. The catalog is there for a flow that wants a market.

## Try it

List twelve things a Character of your game would carry. Give each a cost and a number of slots. Choose a capacity so that the full list does not fit. Mark the three things you would never make a player leave behind and give those no slots, or make them cheap to carry.
