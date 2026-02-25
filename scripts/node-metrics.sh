#!/bin/sh

# CPU
read -r _ user nice system idle iowait irq softirq steal _ < /proc/stat
total=$((user + nice + system + idle + iowait + irq + softirq + steal))
cpu_percent=$(awk "BEGIN {printf \"%.2f\", (1 - $idle / $total) * 100}")

# Memory
memory_total=$(awk '/MemTotal/ {print $2 * 1024}' /proc/meminfo)
memory_available=$(awk '/MemAvailable/ {print $2 * 1024}' /proc/meminfo)
memory_usage=$((memory_total - memory_available))

# Disk
disk_total=$(df -B1 / | awk 'NR==2 {print $2}')
disk_usage=$(df -B1 / | awk 'NR==2 {print $3}')

# Load average
read -r load1 load5 load15 _ _ < /proc/loadavg

# Network
network_rx=$(awk 'NR>2 && $1 !~ /lo:/ {sum += $2} END {print sum+0}' /proc/net/dev)
network_tx=$(awk 'NR>2 && $1 !~ /lo:/ {sum += $10} END {print sum+0}' /proc/net/dev)

# Uptime
uptime_sec=$(awk '{print int($1)}' /proc/uptime)
uptime_days=$((uptime_sec / 86400))
uptime_hours=$(((uptime_sec % 86400) / 3600))

printf '{"metrics":{"cpu_percent":%s,"memory_usage":%s,"memory_total":%s,"disk_usage":%s,"disk_total":%s,"load_average":[%s,%s,%s],"network_rx":%s,"network_tx":%s},"uptime":"%dd %dh"}\n' \
  "$cpu_percent" "$memory_usage" "$memory_total" "$disk_usage" "$disk_total" \
  "$load1" "$load5" "$load15" "$network_rx" "$network_tx" \
  "$uptime_days" "$uptime_hours"
