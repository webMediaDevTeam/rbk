export default function AdminPage() {
  return (
    <section className="page">
      <p className="page__eyebrow">Role area</p>
      <h1 className="page__title">Admin</h1>
      <p className="page__description">
        Static admin section for managing cross-enterprise settings and users.
      </p>
      <div className="panel-grid">
        <article className="panel">
          <h2>Administration</h2>
          <p>Admin-level configuration and supervision pages will go here.</p>
        </article>
        <article className="panel">
          <h2>Access</h2>
          <p>Role-based permissions can be connected later.</p>
        </article>
      </div>
    </section>
  )
}
