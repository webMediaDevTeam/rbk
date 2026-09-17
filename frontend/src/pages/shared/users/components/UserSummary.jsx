export default function UserSummary({ title, detail }) {
  return (
    <article className="panel">
      <h2>{title}</h2>
      <p>{detail}</p>
    </article>
  )
}
