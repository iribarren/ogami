import { useId, type ReactNode } from 'react'

import { CampaignList } from './campaigns/CampaignList'
import { NewCampaignForm } from './campaigns/NewCampaignForm'
import { useCampaigns, useGameSystems } from './campaigns/useCampaigns'
import { DiceRoller } from './dice/DiceRoller'
import { OraclePanel } from './oracles/OraclePanel'
import { sampleLikelihoodOracle, sampleOracleTables } from './oracles/sampleOracles'

/** `/play`: my campaigns and a form to start a new one. */
export function PlayHomePage() {
  return (
    <div className="space-y-10">
      <h1 className="font-heading text-3xl font-bold tracking-tight">Play</h1>
      <Section title="Your campaigns">
        <Campaigns />
      </Section>
      <Section title="New campaign">
        <NewCampaign />
      </Section>
      {/* Stand-alone tools until the campaign Play screen hosts them; nothing here is saved. */}
      <Section title="Dice">
        <p className="mb-3 text-sm text-muted-foreground">Rolls here are not saved.</p>
        <div className="max-w-md">
          <DiceRoller />
        </div>
      </Section>
      <Section title="Oracles">
        <p className="mb-3 text-sm text-muted-foreground">
          Sample oracles; answers here are not saved.
        </p>
        <div className="max-w-md">
          <OraclePanel likelihoodOracle={sampleLikelihoodOracle} tables={sampleOracleTables} />
        </div>
      </Section>
    </div>
  )
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  const headingId = useId()
  return (
    <section aria-labelledby={headingId}>
      <h2 id={headingId} className="mb-3 font-heading text-xl font-semibold">
        {title}
      </h2>
      {children}
    </section>
  )
}

function Campaigns() {
  const campaigns = useCampaigns()
  if (campaigns.isPending) {
    return <p className="text-muted-foreground">Loading your campaigns…</p>
  }
  if (campaigns.isError) {
    return (
      <p role="alert" className="text-sm text-destructive">
        {campaigns.error.message}
      </p>
    )
  }
  return <CampaignList campaigns={campaigns.data} />
}

function NewCampaign() {
  const gameSystems = useGameSystems()
  if (gameSystems.isPending) {
    return <p className="text-muted-foreground">Loading the GameSystems…</p>
  }
  if (gameSystems.isError) {
    return (
      <p role="alert" className="text-sm text-destructive">
        {gameSystems.error.message}
      </p>
    )
  }
  if (gameSystems.data.length === 0) {
    return (
      <p className="text-muted-foreground">
        No GameSystem is published yet, so there is nothing to start a campaign from.
      </p>
    )
  }
  return <NewCampaignForm gameSystems={gameSystems.data} />
}
