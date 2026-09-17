export default function ComercialPage() {
  return (
    <section className="page">
      <p className="page__eyebrow">Role area</p>
      <h1 className="page__title">Comercial</h1>
      <p className="page__description">
        Static workspace for commercial users and their role-specific pages.
      </p>
      <div className="panel-grid">
        <article className="panel">
          <h2>Assigned clients</h2>
          <p>Commercial client activity and follow-ups will appear here.</p>
        </article>
        <article className="panel">
          <h2>Tasks</h2>
          <p>Role-specific actions can be added when requirements are ready.</p>
        </article>
      </div>
    </section>
  )
}
