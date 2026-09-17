export default function SuperAdminPage() {
  return (
    <section className="page">
      <p className="page__eyebrow">Role area</p>
      <h1 className="page__title">Super Admin</h1>
      <p className="page__description">
        Static super admin section for platform-wide control.
      </p>
      <div className="panel-grid">
        <article className="panel">
          <h2>Platform control</h2>
          <p>Global accounts, roles, and enterprise management will live here.</p>
        </article>
        <article className="panel">
          <h2>System scope</h2>
          <p>Super admin-only pages can be added under this module.</p>
        </article>
      </div>
    </section>
  )
}
