import { Navigate, useParams } from 'react-router'

import { EventsPage } from './events-page'
import { EVENT_CATEGORIES, type EventCategory } from './events'

/**
 * One event category as its own page.
 *
 * A `:category` route rather than five hand-written ones, so adding a
 * category is a line in the catalogue and nothing here. A path that is
 * not a category sends the browser to the overview instead of rendering
 * an empty list, which would read as "this category has nothing" rather
 * than "there is no such category".
 */
export function CategoryPage() {
  const { category } = useParams()

  if (!EVENT_CATEGORIES.includes(category as EventCategory)) {
    return <Navigate to=".." relative="path" replace />
  }

  return <EventsPage only={category as EventCategory} />
}
