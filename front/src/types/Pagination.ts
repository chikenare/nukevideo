export type SimplePagination<T> = {
  hasMore: boolean
  page: number
  data: T[]
}

export type Pagination<T> = {
  total: number
  currentPage: number
  perPage: number
  data: T[]
}
