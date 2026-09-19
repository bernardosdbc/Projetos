export type JobStatus = 'pending' | 'processing' | 'completed' | 'dead'
export type JobPriority = 'critical' | 'high' | 'normal' | 'low'

export const JOB_PRIORITIES: JobPriority[] = ['critical', 'high', 'normal', 'low']

export type Job = {
  id: number
  type: string
  priority: JobPriority
  payload: Record<string, unknown>
  idempotency_key: string
  status: JobStatus
  attempts: number
  max_attempts: number
  available_at: string | null
  reserved_at: string | null
  reserved_by: string | null
  last_error: string | null
  completed_at: string | null
  created_at: string | null
  updated_at: string | null
}

export type JobStats = {
  pending: number
  processing: number
  completed: number
  dead: number
  jobs_per_second: number
  avg_processing_ms: number | null
  failure_rate: number
}

export type WorkerStatus = 'idle' | 'processing'

export type Worker = {
  worker_id: string
  status: WorkerStatus
  current_job_id: number | null
  last_seen_at: string
  online: boolean
}

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(path, {
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(init?.headers ?? {}),
    },
    ...init,
  })

  if (!response.ok) {
    let message = `HTTP ${response.status}`
    try {
      const body = await response.json()
      message = body.message ?? JSON.stringify(body)
    } catch {
      /* ignore */
    }
    throw new Error(message)
  }

  if (response.status === 204) {
    return undefined as T
  }

  return response.json() as Promise<T>
}

export const api = {
  health: () => request<{ status: string }>('/api/health'),
  stats: () => request<JobStats>('/api/jobs/stats'),
  listJobs: (status?: string) =>
    request<Job[]>(status ? `/api/jobs?status=${encodeURIComponent(status)}` : '/api/jobs'),
  getJob: (id: number) => request<Job>(`/api/jobs/${id}`),
  createJob: (body: {
    type: string
    payload: Record<string, unknown>
    idempotency_key: string
    priority?: JobPriority
    execute_at?: string
  }) =>
    request<Job>('/api/jobs', {
      method: 'POST',
      body: JSON.stringify(body),
    }),
  deadJobs: () => request<Job[]>('/api/dead-jobs'),
  retryDeadJob: (id: number) =>
    request<Job>(`/api/dead-jobs/${id}/retry`, { method: 'POST' }),
  workers: () => request<Worker[]>('/api/workers'),
}
