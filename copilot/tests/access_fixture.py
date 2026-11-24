"""Give named synthetic account fixtures the services their tests exercise."""
from pelanggan_akses import schema


def grant_existing(conn, product='marketing', email=None, postgres=False):
    schema(conn)
    ph = '%s' if postgres else '?'
    cur = conn.cursor()
    where = f'email={ph}' if email else '1=1'
    cur.execute(f"""INSERT INTO pelanggan_akses(owner,product,state)
        SELECT id,{ph},'free' FROM pelanggan_akaun WHERE {where}
        ON CONFLICT(owner,product) DO UPDATE SET state='free'""", (product,email) if email else (product,))
    conn.commit()
