import { useTabs } from './useTabs.js'

export default function Tabs(props) {
  const { items } = useTabs(props)

  return (
    <div className="flex gap-1 border-b border-border">
      {items.map((tab) => (
        <button
          key={tab.key}
          onClick={tab.onClick}
          className={tab.className}
        >
          {tab.label}
        </button>
      ))}
    </div>
  )
}