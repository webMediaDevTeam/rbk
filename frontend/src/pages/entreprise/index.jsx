export default function EntreprisePage() {
  return (
    <section className="page">
      <p className="page__eyebrow">Role area</p>
      <h1 className="page__title">Entreprise</h1>
      <p className="page__description">
        Static section for enterprise role pages, also visible to admin and
        super admin roles later.
      </p>
      <div className="panel-grid">
        <article className="panel">
          <h2>Enterprise overview</h2>
          <p>Company-level metrics and information will live here.</p>
        </article>
        <article className="panel">
          <h2>Commercial users</h2>
          <p>Manage users attached to this enterprise.</p>
        </article>
      </div>
    </section>
  )
}
