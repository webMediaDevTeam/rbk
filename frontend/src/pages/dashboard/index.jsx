import { useState } from 'react'
import { Bar, BarChart, ResponsiveContainer, XAxis, YAxis } from 'recharts'
import {
  DollarSign,
  Users,
  CreditCard,
  Activity,
  Download,
} from 'lucide-react'

const metrics = [
  { label: 'Total Revenue', value: '$45,231.89', change: '+20.1% from last month', icon: DollarSign },
  { label: 'Subscriptions', value: '+2350', change: '+180.1% from last month', icon: Users },
  { label: 'Sales', value: '+12,234', change: '+19% from last month', icon: CreditCard },
  { label: 'Active Now', value: '+573', change: '+201 since last hour', icon: Activity },
]

const chartData = [
  { name: 'Jan', total: 3500 },
  { name: 'Feb', total: 1500 },
  { name: 'Mar', total: 1450 },
  { name: 'Apr', total: 2000 },
  { name: 'May', total: 1350 },
  { name: 'Jun', total: 4950 },
  { name: 'Jul', total: 5300 },
  { name: 'Aug', total: 5050 },
  { name: 'Sep', total: 6000 },
  { name: 'Oct', total: 2300 },
  { name: 'Nov', total: 4200 },
  { name: 'Dec', total: 5050 },
]

const sales = [
  { initials: 'OM', name: 'Olivia Martin', email: 'olivia.martin@email.com', amount: '+$1,999.00' },
  { initials: 'JL', name: 'Jackson Lee', email: 'jackson.lee@email.com', amount: '+$39.00' },
  { initials: 'IN', name: 'Isabella Nguyen', email: 'isabella.nguyen@email.com', amount: '+$299.00' },
  { initials: 'WK', name: 'William Kim', email: 'will@email.com', amount: '+$99.00' },
  { initials: 'SD', name: 'Sofia Davis', email: 'sofia.davis@email.com', amount: '+$39.00' },
]

const tabs = [
  { id: 'overview', label: 'Overview' },
  { id: 'analytics', label: 'Analytics' },
]

export default function DashboardPage() {
  const [activeTab, setActiveTab] = useState('overview')

  return (
    <div className="space-y-4">
      {/* Heading */}
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold tracking-tight">Dashboard</h1>
        <button className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground shadow hover:bg-primary/90 transition-colors">
          <Download className="h-4 w-4" />
          Download
        </button>
      </div>

      {/* Tabs */}
      <div className="w-full overflow-x-auto pb-2">
        <div className="inline-flex items-center gap-1 rounded-lg bg-muted p-1 text-muted-foreground">
          {tabs.map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-medium transition-all ${
                activeTab === tab.id
                  ? 'bg-background text-foreground shadow-sm'
                  : 'hover:text-foreground'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>
      </div>

      {/* Overview Tab */}
      {activeTab === 'overview' && (
        <div className="space-y-4">
          {/* Metric Cards */}
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {metrics.map((metric) => {
              const Icon = metric.icon
              return (
                <div key={metric.label} className="rounded-xl border bg-card text-card-foreground shadow-sm">
                  <div className="flex flex-row items-center justify-between space-y-0 pb-2 p-6">
                    <h3 className="text-sm font-medium">{metric.label}</h3>
                    <Icon className="h-4 w-4 text-muted-foreground" />
                  </div>
                  <div className="p-6 pt-0">
                    <div className="text-2xl font-bold">{metric.value}</div>
                    <p className="text-xs text-muted-foreground">{metric.change}</p>
                  </div>
                </div>
              )
            })}
          </div>

          {/* Chart + Recent Sales */}
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-7">
            <div className="col-span-1 rounded-xl border bg-card text-card-foreground shadow-sm lg:col-span-4">
              <div className="p-6">
                <h3 className="text-lg font-semibold leading-none tracking-tight">Overview</h3>
              </div>
              <div className="p-6 pt-0 ps-2">
                <ResponsiveContainer width="100%" height={350}>
                  <BarChart data={chartData}>
                    <XAxis
                      dataKey="name"
                      stroke="#888888"
                      fontSize={12}
                      tickLine={false}
                      axisLine={false}
                    />
                    <YAxis
                      stroke="#888888"
                      fontSize={12}
                      tickLine={false}
                      axisLine={false}
                      tickFormatter={(v) => `$${v}`}
                    />
                    <Bar
                      dataKey="total"
                      fill="currentColor"
                      radius={[4, 4, 0, 0]}
                      className="fill-primary"
                    />
                  </BarChart>
                </ResponsiveContainer>
              </div>
            </div>

            <div className="col-span-1 rounded-xl border bg-card text-card-foreground shadow-sm lg:col-span-3">
              <div className="p-6">
                <h3 className="text-lg font-semibold leading-none tracking-tight">Recent Sales</h3>
                <p className="text-sm text-muted-foreground mt-1.5">You made 265 sales this month.</p>
              </div>
              <div className="p-6 pt-0 space-y-8">
                {sales.map((sale) => (
                  <div key={sale.email} className="flex items-center gap-4">
                    <div className="flex h-9 w-9 items-center justify-center rounded-full border bg-muted text-xs font-medium">
                      {sale.initials}
                    </div>
                    <div className="flex flex-1 flex-wrap items-center justify-between gap-1">
                      <div className="space-y-1">
                        <p className="text-sm leading-none font-medium">{sale.name}</p>
                        <p className="text-sm text-muted-foreground">{sale.email}</p>
                      </div>
                      <div className="font-medium text-sm">{sale.amount}</div>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Analytics Tab */}
      {activeTab === 'analytics' && (
        <div className="space-y-4">
          {/* Traffic Overview Chart */}
          <div className="rounded-xl border bg-card text-card-foreground shadow-sm">
            <div className="p-6">
              <h3 className="text-lg font-semibold leading-none tracking-tight">Traffic Overview</h3>
              <p className="text-sm text-muted-foreground mt-1.5">Weekly clicks and unique visitors</p>
            </div>
            <div className="p-6 pt-0 px-6">
              <ResponsiveContainer width="100%" height={300}>
                <BarChart data={[
                  { name: 'Mon', clicks: 420, uniques: 280 },
                  { name: 'Tue', clicks: 580, uniques: 390 },
                  { name: 'Wed', clicks: 650, uniques: 420 },
                  { name: 'Thu', clicks: 490, uniques: 310 },
                  { name: 'Fri', clicks: 720, uniques: 510 },
                  { name: 'Sat', clicks: 380, uniques: 250 },
                  { name: 'Sun', clicks: 310, uniques: 200 },
                ]}>
                  <XAxis dataKey="name" stroke="#888888" fontSize={12} tickLine={false} axisLine={false} />
                  <YAxis stroke="#888888" fontSize={12} tickLine={false} axisLine={false} />
                  <Bar dataKey="clicks" fill="currentColor" radius={[4, 4, 0, 0]} className="fill-primary" />
                  <Bar dataKey="uniques" fill="currentColor" radius={[4, 4, 0, 0]} className="fill-muted-foreground" />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </div>

          {/* Analytics Metric Cards */}
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {[
              { label: 'Total Clicks', value: '1,248', change: '+12.4% vs last week' },
              { label: 'Unique Visitors', value: '832', change: '+5.8% vs last week' },
              { label: 'Bounce Rate', value: '42%', change: '-3.2% vs last week' },
              { label: 'Avg. Session', value: '3m 24s', change: '+18s vs last week' },
            ].map((item) => (
              <div key={item.label} className="rounded-xl border bg-card text-card-foreground shadow-sm">
                <div className="p-6">
                  <h3 className="text-sm font-medium">{item.label}</h3>
                  <div className="text-2xl font-bold mt-2">{item.value}</div>
                  <p className="text-xs text-muted-foreground mt-1">{item.change}</p>
                </div>
              </div>
            ))}
          </div>

          {/* Referrers + Devices */}
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-7">
            <div className="col-span-1 rounded-xl border bg-card text-card-foreground shadow-sm lg:col-span-4">
              <div className="p-6">
                <h3 className="text-lg font-semibold">Referrers</h3>
                <p className="text-sm text-muted-foreground">Top sources driving traffic</p>
              </div>
              <div className="p-6 pt-0 space-y-3">
                {[
                  { name: 'Direct', value: 512 },
                  { name: 'Product Hunt', value: 238 },
                  { name: 'Twitter', value: 174 },
                  { name: 'Blog', value: 104 },
                ].map((item) => {
                  const max = 512
                  const pct = Math.round((item.value / max) * 100)
                  return (
                    <div key={item.name} className="flex items-center justify-between gap-3">
                      <div className="min-w-0 flex-1">
                        <div className="mb-1 truncate text-xs text-muted-foreground">{item.name}</div>
                        <div className="h-2.5 w-full rounded-full bg-muted">
                          <div className="h-2.5 rounded-full bg-primary" style={{ width: `${pct}%` }} />
                        </div>
                      </div>
                      <div className="ps-2 text-xs font-medium tabular-nums">{item.value}</div>
                    </div>
                  )
                })}
              </div>
            </div>
            <div className="col-span-1 rounded-xl border bg-card text-card-foreground shadow-sm lg:col-span-3">
              <div className="p-6">
                <h3 className="text-lg font-semibold">Devices</h3>
                <p className="text-sm text-muted-foreground">How users access your app</p>
              </div>
              <div className="p-6 pt-0 space-y-3">
                {[
                  { name: 'Desktop', value: 74 },
                  { name: 'Mobile', value: 22 },
                  { name: 'Tablet', value: 4 },
                ].map((item) => (
                  <div key={item.name} className="flex items-center justify-between gap-3">
                    <div className="min-w-0 flex-1">
                      <div className="mb-1 truncate text-xs text-muted-foreground">{item.name}</div>
                      <div className="h-2.5 w-full rounded-full bg-muted">
                        <div className="h-2.5 rounded-full bg-muted-foreground" style={{ width: `${item.value}%` }} />
                      </div>
                    </div>
                    <div className="ps-2 text-xs font-medium tabular-nums">{item.value}%</div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}