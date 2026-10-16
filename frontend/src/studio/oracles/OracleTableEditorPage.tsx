/** `/studio/game-systems/$key/oracle-tables/$table`: the table editor, coming next. */
export function OracleTableEditorPage({
  gameSystemKey,
  table,
}: {
  gameSystemKey: string
  table: string
}) {
  return (
    <p>
      Editor of {table} in {gameSystemKey} coming.
    </p>
  )
}
