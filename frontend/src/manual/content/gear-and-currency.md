# Gear and Currency

Many rulebooks have an equipment list, a page of rope and lanterns with a price beside each, and the Gear catalog of a GameSystem is that list. Gear is what a Character can carry and Currency is what buys it, and they are held in two sections of Studio, with the catalog in **Gear** and the fields that hold the pack and the money in the Sheet.

## The Gear catalog

The Gear catalog is a list of entries, and each entry has these parts.

- A **key** and a **name**.
- A **cost**, in the unit of the Currency.
- **Slots**, which is how much room one unit takes, from 0 to 100, and 1 when you leave it empty.
- An optional **category**, a short label.
- An optional **description**.

Studio's **Add gear** opens the form for one. Names must be unique, and the key follows the same rule as every key, so it is made of lower case letters, digits and hyphens.

Hard Country's catalog has thirteen entries in two categories, Kit and Arms, which are rope, a lantern, lamp oil, a staff, a blanket, bandages, a waterskin, trail rations, carrying poles, a knife, a club, a sling and a pole axe. Most of them cost a few pennies and take one slot. Dogwatch has eleven, in Tools and Supplies, which are a multitool, a cutting torch, a patch kit, a pry bar, a handlight, a tether, a scanner, an air canister, ration bars, a med patch and a flare.

Write descriptions for play rather than for the shop. The description of the rope, "Fifty feet of hemp, to lash a load or pass a line across water.", tells a player what the rope is for. When a player would assume a piece of Gear does something it does not, say so in the description, as the Hard Country bandages do with "Closes a cut; it does not mend a Wound." and the Dogwatch med patch with "It does not mend an Injury."

## Load

An **Inventory** is a sheet field that holds Gear. Hard Country's Load has a capacity of 8 slots and Dogwatch's Kit has 6, and in both the capacity is the sum of slots rather than a count of pieces of Gear.

That is the load rule, and the catalog is where you tune it. Most Gear takes one slot, while the pole axe takes two, and so do the carrying poles. In Dogwatch the cutting torch, whose description ends "Loud, hot and bright.", takes two as well. Some Gear takes none, as Hard Country's lamp oil and Dogwatch's ration bars have 0 slots. A crew member's ration bars take no slot, so nobody aboard has to choose between a day's food and the patch kit.

An Inventory can also set a maximum number of stacks, a stack being one line of the Inventory, and the categories it accepts. Neither preset uses either, so each Inventory accepts every entry.

Count the slots of a plain starting kit before you fix the capacity. If a bearer carrying everything they would want needs 11 slots, a capacity of 8 makes them choose, and you should make sure it is the choice you wanted them to face.

A free stack, which the player writes in with a name, a quantity and slots of its own, is also allowed, and a shop never buys one.

An Inventory is not a sheet effect target, and no Formula reads it. That means a rule such as "a bearer carrying more than six slots is slowed" cannot be a step condition, so write it in a tip, as the harm rule is.

## Currency

Currency is one Number field of the sheet, named in the Sheet's Currency panel with an optional unit. Hard Country's is `pennies`, with the unit "pennies", and Dogwatch's is `chits`. Pennies have the dice default `2d6*10`, so the bearers begin with a purse, while chits have a plain default of 20.

The minimum of the field is the Currency's floor, and the floor is 0 if the field sets none. A shop purchase or a voluntary sheet effect that would take the field under its floor is refused as insufficient funds. Both presets set the minimum to 0, so a Character cannot buy what they cannot pay for.

Costs are in the unit of the Currency, which means a Gear entry of cost 8 is eight pennies in Hard Country and eight chits in Dogwatch. The two prices are not comparable, and they need not be.

## Shops

A `shop` step offers Gear from the catalog, either as entries by key or as whole categories. It can roll a random stock when the step is reached, and it has a rate at which it buys things back. A purchase is all or nothing.

Neither The Long Carry nor Cold Berth has a shop step. The bearers begin with their purse and their load, and the crew with their kit, and the catalog is there for a flow that wants a market.

## Try it

List twelve things a Character of your game would carry, and give each of them a cost and a number of slots. Choose a capacity so that the full list does not fit. Then mark the three things you would never make a player leave behind, and give those no slots, or make them cheap to carry.
