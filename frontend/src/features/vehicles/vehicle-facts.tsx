import { useTranslation } from 'react-i18next'
import { Briefcase, Gauge, Package, Users, Weight, Wrench } from 'lucide-react'

import { SectionMark } from '@/components/layout/section-mark'
import type { VehicleSpecs } from './vehicles'

/** The game's own repair grade, which decides who can work on it. */
const MECHANIC_TYPES: Record<number, string> = {
  1: 'standard',
  2: 'heavy',
  3: 'sports',
}

/**
 * What a vehicle's script says about it.
 *
 * Only the values the script actually states are shown: a modded
 * vehicle has none of them, and an unset property takes an engine
 * default the panel does not know. Inventing a number here would be
 * worse than the gap.
 */
export function VehicleFacts({ specs }: { specs: VehicleSpecs | null }) {
  const { t } = useTranslation()

  if (specs === null) {
    return (
      <section className="space-y-3 rounded-md border p-3">
        <SectionMark label={t('vehicles.facts')} />
        <p className="text-xs text-muted-foreground">{t('vehicles.noFacts')}</p>
      </section>
    )
  }

  const grade = specs.mechanicType === null ? null : MECHANIC_TYPES[specs.mechanicType]

  const facts = [
    {
      icon: Users,
      label: t('vehicles.seats'),
      value: specs.seats === null ? null : String(specs.seats),
    },
    {
      icon: Package,
      label: t('vehicles.trunk'),
      value: specs.trunk === null ? null : t('vehicles.capacityUnit', { count: specs.trunk }),
    },
    {
      icon: Briefcase,
      label: t('vehicles.gloveBox'),
      value:
        specs.gloveBox === null ? null : t('vehicles.capacityUnit', { count: specs.gloveBox }),
    },
    {
      icon: Gauge,
      label: t('vehicles.maxSpeed'),
      value: specs.maxSpeed === null ? null : `${specs.maxSpeed} km/h`,
    },
    {
      icon: Weight,
      label: t('vehicles.mass'),
      value: specs.mass === null ? null : `${specs.mass} kg`,
    },
    {
      icon: Wrench,
      label: t('vehicles.mechanicType'),
      value: grade === undefined || grade === null ? null : t(`vehicles.grades.${grade}`),
    },
  ].filter((fact) => fact.value !== null)

  if (facts.length === 0) {
    return null
  }

  return (
    <section className="space-y-3 rounded-md border p-3">
      <SectionMark label={t('vehicles.facts')} />

      <dl className="grid grid-cols-2 gap-x-3 gap-y-2">
        {facts.map((fact) => (
          <div key={fact.label} className="min-w-0">
            <dt className="flex items-center gap-1 text-[0.7rem] text-muted-foreground">
              <fact.icon className="size-3 shrink-0" />
              <span className="truncate">{fact.label}</span>
            </dt>
            <dd className="font-mono text-sm tabular-nums">{fact.value}</dd>
          </div>
        ))}
      </dl>
    </section>
  )
}
