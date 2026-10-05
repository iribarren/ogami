import { DiceRoller } from './dice/DiceRoller'
import { OraclePanel } from './oracles/OraclePanel'
import { sampleLikelihoodOracle, sampleOracleTables } from './oracles/sampleOracles'

export function PlayHomePage() {
  return (
    <section className="space-y-6">
      <div className="space-y-3">
        <h1 className="font-heading text-3xl font-bold tracking-tight">Play</h1>
        <p className="text-muted-foreground">
          Your campaigns will appear here once the first Play feature lands. Meanwhile, roll some
          dice and ask the oracles.
        </p>
      </div>
      <div className="max-w-md">
        <h2 className="mb-3 font-heading text-xl font-semibold">Dice</h2>
        <DiceRoller />
      </div>
      <div className="max-w-md">
        <h2 className="mb-3 font-heading text-xl font-semibold">Oracles</h2>
        <OraclePanel likelihoodOracle={sampleLikelihoodOracle} tables={sampleOracleTables} />
      </div>
    </section>
  )
}
