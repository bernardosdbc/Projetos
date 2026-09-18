import { useCallback, useEffect, useMemo, useState } from 'react'
import type { FormEvent } from 'react'
import { api, JOB_PRIORITIES } from './api'
import type { Job, JobPriority, JobStats } from './api'
import './index.css'

const STATUS_FILTERS = ['all', 'pending', 'processing', 'completed', 'dead'] as const

function statusBadge(status: string) {
  return <span className={`badge ${status}`}>{status}</span>
}

function priorityBadge(priority: string) {
  return <span className={`badge prio-${priority}`}>{priority}</span>
}

function CreateJobForm({ onCreated }: { onCreated: (job: Job) => void }) {
  const [type, setType] = useState<'send_email' | 'generate_report' | 'smoke_check'>('send_email')
  const [priority, setPriority] = useState<JobPriority>('normal')
  const [executeAt, setExecuteAt] = useState('')
  const [to, setTo] = useState('teste@email.com')
  const [report, setReport] = useState('sales')
  const [period, setPeriod] = useState('2026-09')
  const [targets, setTargets] = useState('')
  const [failTimes, setFailTimes] = useState('')
  const [sleepSeconds, setSleepSeconds] = useState('')
  const [idempotencyKey, setIdempotencyKey] = useState(() => `ui-${crypto.randomUUID().slice(0, 8)}`)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)

    const payload: Record<string, unknown> =
      type === 'send_email'
        ? { to }
        : type === 'generate_report'
          ? { report, period }
          : {
              targets: targets
                .split('\n')
                .map((line) => line.trim())
                .filter((line) => line !== ''),
            }

    if (failTimes !== '') payload.fail_times = Number(failTimes)
    if (sleepSeconds !== '') payload.sleep_seconds = Number(sleepSeconds)

    try {
      const job = await api.createJob({
        type,
        payload,
        idempotency_key: idempotencyKey,
        priority,
        execute_at: executeAt !== '' ? new Date(executeAt).toISOString() : undefined,
      })
      onCreated(job)
      setIdempotencyKey(`ui-${crypto.randomUUID().slice(0, 8)}`)
      setExecuteAt('')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Falha ao criar job')
    } finally {
      setBusy(false)
    }
  }

  return (
    <form className="form" onSubmit={onSubmit}>
      <label>
        Tipo
        <select
          value={type}
          onChange={(e) => setType(e.target.value as 'send_email' | 'generate_report' | 'smoke_check')}
        >
          <option value="send_email">send_email</option>
          <option value="generate_report">generate_report</option>
          <option value="smoke_check">smoke_check</option>
        </select>
      </label>
      <label>
        Prioridade
        <select value={priority} onChange={(e) => setPriority(e.target.value as JobPriority)}>
          {JOB_PRIORITIES.map((item) => (
            <option key={item} value={item}>
              {item}
            </option>
          ))}
        </select>
      </label>
      <label>
        Agendar para (opcional)
        <input
          type="datetime-local"
          value={executeAt}
          onChange={(e) => setExecuteAt(e.target.value)}
        />
      </label>
      {type === 'send_email' && (
        <label>
          Destino (payload.to)
          <input value={to} onChange={(e) => setTo(e.target.value)} required />
        </label>
      )}
      {type === 'generate_report' && (
        <>
          <label>
            Relatório (payload.report)
            <input value={report} onChange={(e) => setReport(e.target.value)} required />
          </label>
          <label>
            Período (payload.period)
            <input value={period} onChange={(e) => setPeriod(e.target.value)} />
          </label>
        </>
      )}
      {type === 'smoke_check' && (
        <label>
          URLs a checar (uma por linha, vazio = health deste app)
          <textarea
            className="mono"
            rows={3}
            placeholder="http://app:8000/api/health"
            value={targets}
            onChange={(e) => setTargets(e.target.value)}
          />
        </label>
      )}
      <label>
        fail_times (opcional)
        <input
          type="number"
          min={0}
          placeholder="ex: 2"
          value={failTimes}
          onChange={(e) => setFailTimes(e.target.value)}
        />
      </label>
      <label>
        sleep_seconds (opcional)
        <input
          type="number"
          min={0}
          placeholder="ex: 30"
          value={sleepSeconds}
          onChange={(e) => setSleepSeconds(e.target.value)}
        />
      </label>
      <label>
        idempotency_key
        <input
          className="mono"
          value={idempotencyKey}
          onChange={(e) => setIdempotencyKey(e.target.value)}
          required
        />
      </label>
      <div className="actions">
        <button className="btn" type="submit" disabled={busy}>
          {busy ? 'Enfileirando…' : 'Enfileirar job'}
        </button>
      </div>
      {error && <p className="error">{error}</p>}
      <p className="hint">Workers processam a fila (`QUEUE_DRIVER`). A mesma idempotency_key não duplica o job.</p>
    </form>
  )
}

function JobDetail({ job, onRetry }: { job: Job | null; onRetry: (job: Job) => void }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  if (!job) {
    return <p className="hint">Selecione um job na lista para ver o detalhe.</p>
  }

  async function retry() {
    if (!job) return
    setBusy(true)
    setError(null)
    try {
      const updated = await api.retryDeadJob(job.id)
      onRetry(updated)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Retry falhou')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="detail">
      <div className="row" style={{ display: 'flex', justifyContent: 'space-between', gap: '1rem' }}>
        <h3 className="mono">#{job.id}</h3>
        <div className="row" style={{ display: 'flex', gap: '0.4rem' }}>
          {priorityBadge(job.priority)}
          {statusBadge(job.status)}
        </div>
      </div>
      <dl>
        <dt>type</dt>
        <dd className="mono">{job.type}</dd>
        <dt>priority</dt>
        <dd className="mono">{job.priority}</dd>
        <dt>attempts</dt>
        <dd className="mono">
          {job.attempts} / {job.max_attempts}
        </dd>
        <dt>reserved_by</dt>
        <dd className="mono">{job.reserved_by ?? '—'}</dd>
        <dt>idempotency</dt>
        <dd className="mono">{job.idempotency_key}</dd>
        <dt>payload</dt>
        <dd className="mono">{JSON.stringify(job.payload)}</dd>
        <dt>last_error</dt>
        <dd>{job.last_error ?? '—'}</dd>
        <dt>available_at</dt>
        <dd className="mono">{job.available_at ?? '—'}</dd>
        <dt>completed_at</dt>
        <dd className="mono">{job.completed_at ?? '—'}</dd>
      </dl>
      {job.status === 'dead' && (
        <div className="actions">
          <button className="btn secondary" type="button" disabled={busy} onClick={retry}>
            {busy ? 'Reabrindo…' : 'Retry dead job'}
          </button>
        </div>
      )}
      {error && <p className="error">{error}</p>}
    </div>
  )
}

export default function App() {
  const [healthOk, setHealthOk] = useState<boolean | null>(null)
  const [stats, setStats] = useState<JobStats | null>(null)
  const [jobs, setJobs] = useState<Job[]>([])
  const [filter, setFilter] = useState<(typeof STATUS_FILTERS)[number]>('all')
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)

  const selected = useMemo(
    () => jobs.find((job) => job.id === selectedId) ?? null,
    [jobs, selectedId],
  )

  const refresh = useCallback(async () => {
    try {
      const [health, nextStats, nextJobs] = await Promise.all([
        api.health(),
        api.stats(),
        api.listJobs(filter === 'all' ? undefined : filter),
      ])
      setHealthOk(health.status === 'ok')
      setStats(nextStats)
      setJobs(nextJobs)
      setError(null)
    } catch (err) {
      setHealthOk(false)
      setError(err instanceof Error ? err.message : 'Falha ao falar com a API')
    }
  }, [filter])

  useEffect(() => {
    void refresh()
    const id = window.setInterval(() => void refresh(), 2000)
    return () => window.clearInterval(id)
  }, [refresh])

  useEffect(() => {
    if (selectedId == null) return
    if (selected && (selected.status === 'completed' || selected.status === 'dead')) return

    const id = window.setInterval(async () => {
      try {
        const fresh = await api.getJob(selectedId)
        setJobs((current) => {
          const exists = current.some((job) => job.id === fresh.id)
          if (!exists) return [fresh, ...current]
          return current.map((job) => (job.id === fresh.id ? fresh : job))
        })
      } catch {
        /* ignore transient poll errors */
      }
    }, 1000)

    return () => window.clearInterval(id)
  }, [selectedId, selected])

  function onCreated(job: Job) {
    setSelectedId(job.id)
    setJobs((current) => [job, ...current.filter((item) => item.id !== job.id)])
    void refresh()
  }

  function onRetry(job: Job) {
    setJobs((current) => current.map((item) => (item.id === job.id ? job : item)))
    setSelectedId(job.id)
    void refresh()
  }

  return (
    <div className="app-shell">
      <header className="topbar">
        <div className="brand">
          <h1>Job Queue Console</h1>
          <p>
            Front React/Vite para a fila MySQL: enfileire, acompanhe status e reabra dead jobs.
          </p>
        </div>
        <div className="health" title="GET /api/health">
          <span className={`health-dot ${healthOk ? 'ok' : healthOk === false ? 'down' : ''}`} />
          <span className="mono">{healthOk ? 'API online' : healthOk === false ? 'API offline' : 'checando…'}</span>
        </div>
      </header>

      <section className="stats" aria-label="Contadores da fila">
        <div className="stat">
          <span>Pending</span>
          <strong>{stats?.pending ?? '—'}</strong>
        </div>
        <div className="stat">
          <span>Processing</span>
          <strong>{stats?.processing ?? '—'}</strong>
        </div>
        <div className="stat">
          <span>Completed</span>
          <strong>{stats?.completed ?? '—'}</strong>
        </div>
        <div className="stat">
          <span>Dead</span>
          <strong>{stats?.dead ?? '—'}</strong>
        </div>
      </section>

      {error && <p className="error">{error}</p>}

      <div className="layout">
        <section className="panel">
          <h2>Novo job</h2>
          <CreateJobForm onCreated={onCreated} />
        </section>

        <section className="stack">
          <div className="panel">
            <div className="panel-head">
              <h2>Fila</h2>
              <button className="btn secondary" type="button" onClick={() => void refresh()}>
                Atualizar
              </button>
            </div>
            <div className="filters">
              {STATUS_FILTERS.map((item) => (
                <button
                  key={item}
                  type="button"
                  className={`chip ${filter === item ? 'active' : ''}`}
                  onClick={() => setFilter(item)}
                >
                  {item}
                </button>
              ))}
            </div>
            <ul className="job-list" style={{ marginTop: '0.75rem' }}>
              {jobs.length === 0 && <li className="hint">Nenhum job neste filtro.</li>}
              {jobs.map((job) => (
                <li
                  key={job.id}
                  className={job.id === selectedId ? 'active' : ''}
                  onClick={() => setSelectedId(job.id)}
                >
                  <div className="row">
                    <strong className="mono">#{job.id}</strong>
                    <div className="row" style={{ display: 'flex', gap: '0.4rem' }}>
                      {priorityBadge(job.priority)}
                      {statusBadge(job.status)}
                    </div>
                  </div>
                  <div className="row">
                    <span className="mono">{job.type}</span>
                    <span className="hint mono">
                      {job.attempts}/{job.max_attempts}
                    </span>
                  </div>
                </li>
              ))}
            </ul>
            <JobDetail job={selected} onRetry={onRetry} />
          </div>
        </section>
      </div>
    </div>
  )
}
