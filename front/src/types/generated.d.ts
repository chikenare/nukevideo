declare namespace App {
namespace Data {
export type ActivityLogData = {
id: number,
logName: string | null,
description: string,
subjectType: string | null,
subjectId: number | null,
causerType: string | null,
causerId: number | null,
event: string | null,
properties: Record<string, any>,
createdAt: string,
updatedAt: string | null,
};
export type ApiTokenData = {
id: number,
name: string,
abilities: string[] | null,
lastUsedAt: string | null,
createdAt: string,
expiresAt: string | null,
token?: string,
};
export type BunnyConfigData = {
host: string,
tokenKey: string,
tokenWindow: number,
};
export type CdnSettingsData = {
provider: App.Enums.CdnDriver,
selfHosted: App.Data.SelfHostedConfigData,
bunny: App.Data.BunnyConfigData,
};
export type NodeData = {
id: number,
name: string,
user: string | null,
ipAddress: string,
type: App.Enums.NodeType,
hostname: string | null,
isActive: boolean,
isStorageServer: boolean,
storageEndpoint: string | null,
sshKeyId: number | null,
services: App.Data.ServiceStatusData[],
log: string | null,
env: string | null,
lastSeenAt: string | null,
};
export type OutputData = {
ulid: string,
formats: string[],
status: App.Enums.VideoStatus,
progress: number,
streams: App.Data.StreamData[],
createdAt: string,
};
export type ProjectData = {
ulid: string,
name: string,
settings: App.Data.ProjectSettingsData | null,
apiKey: App.Data.ApiTokenData | null,
createdAt: string,
updatedAt: string | null,
};
export type ProjectSettingsData = {
webhookUrl: string | null,
webhookSecret: string | null,
};
export type RequestData = object;
export type SelfHostedConfigData = {
tokenSecret: string,
tokenName: string,
tokenWindow: number,
secureTokenExpires: string,
secureTokenQueryExpires: string,
cacheMaxSize: string,
cacheInactive: string,
};
export type ServiceStatusData = {
name: string,
running: number,
desired: number | null,
state: string,
};
export type SshKeyData = {
id: number,
name: string,
publicKey: string,
fingerprint: string | null,
createdAt: string,
};
export type StreamData = {
ulid: string,
name: string,
type: string,
packageSize: number | null,
fileSize: number | null,
inputParams: Record<string, any> | null,
meta: Record<string, any> | null,
width: number | null,
height: number | null,
language: string | null,
channels: number | null,
errorLog: string | null,
createdAt: string,
};
export type TemplateData = {
ulid: string,
name: string,
query: Record<string, any>,
keepProcessedFiles: boolean,
keepOriginal: boolean,
commands: string[],
createdAt: string,
updatedAt: string | null,
};
export type TemplatePresetData = {
slug: string,
name: string,
description: string,
category: string,
query: Record<string, any>,
};
export type UserData = {
id: number,
name: string,
email: string,
isAdmin: boolean,
projects: App.Data.ProjectData[],
};
export type ValidationCheckData = {
key: string,
label: string,
status: string,
output: string,
};
export type VideoData = {
ulid: string,
name: string,
duration: number,
aspectRatio: string,
status: App.Enums.VideoStatus,
createdAt: string,
externalUserId: string | null,
externalResourceId: string | null,
thumbnailUrl: string,
storyboardUrl: string,
outputs: App.Data.OutputData[],
streams: App.Data.StreamData[],
size: number,
servedSize: number,
};
export type VodData = {
resolution: number | null,
external_resource_id: string | null,
external_user_id: string | null,
ip: string | null,
format: string | null,
};
export type VodOutputData = {
url: string,
thumbnailUrl: string,
storyboardUrl: string,
};
namespace Analytics {
export type AnalyticsCardData = {
label: string,
value: number,
format: string,
};
export type AnalyticsData = {
cards: App.Data.Analytics.AnalyticsCardData[],
bandwidthOverTime: App.Data.Analytics.BandwidthPointData[],
topIps: App.Data.Analytics.TopIpData[],
topVideos: App.Data.Analytics.TopVideoData[],
topExternalUsers: App.Data.Analytics.TopExternalUserData[],
bandwidthByVideo: App.Data.Analytics.BandwidthByVideoData[],
encodingOverTime: App.Data.Analytics.EncodingPointData[],
};
export type BandwidthByVideoData = {
date: string,
video: string,
bytes: number,
};
export type BandwidthPointData = {
date: string,
bytes: number,
sessions: number,
};
export type EncodingPointData = {
date: string,
device: string,
seconds: number,
};
export type TopExternalUserData = {
externalUserId: string,
bytes: number,
};
export type TopIpData = {
ip: string,
bytes: number,
sessions: number,
};
export type TopVideoData = {
video: string,
externalResourceId: string | null,
bytes: number,
sessions: number,
uniqueIps: number,
};
}
namespace ApiToken {
export type StoreApiTokenData = {
name: string,
};
}
namespace Auth {
export type LoginData = {
email: string,
password: string,
};
}
namespace Node {
export type StoreNodeData = {
name: string,
ipAddress: string,
type: string,
user?: string,
isStorageServer?: boolean,
hostname: string | null,
sshKeyId: number | null,
storageEndpoint: string | null,
};
export type UpdateNodeData = {
name?: string,
user?: string | null,
ipAddress?: string,
hostname?: string | null,
isActive?: boolean,
sshKeyId?: number | null,
isStorageServer?: boolean,
storageEndpoint?: string | null,
env?: string | null,
};
}
namespace Profile {
export type UpdatePasswordData = {
currentPassword: string,
password: string,
};
export type UpdateProfileData = {
name: string,
email: string,
};
}
namespace Project {
export type StoreProjectData = {
name: string,
settings?: App.Data.ProjectSettingsData,
};
export type UpdateProjectData = {
name?: string,
settings?: App.Data.ProjectSettingsData,
};
}
namespace SshKey {
export type StoreSshKeyData = {
name: string,
publicKey: string,
privateKey: string,
};
}
namespace Template {
export type StoreTemplateData = {
name: string,
keepProcessedFiles?: boolean,
keepOriginal?: boolean,
query: Record<string, any>,
};
export type UpdateTemplateData = {
name?: string,
keepProcessedFiles?: boolean,
keepOriginal?: boolean,
query: Record<string, any>,
};
}
namespace User {
export type StoreUserData = {
name: string,
email: string,
password: string,
isAdmin?: boolean,
};
export type UpdateUserData = {
name?: string,
email?: string,
password?: string,
isAdmin?: boolean,
};
}
namespace Video {
export type UpdateVideoData = {
name: string,
externalUserId?: string | null,
externalResourceId?: string | null,
};
}
}
namespace Enums {
export type CdnDriver = "self_hosted" | "bunny";
export type NodeType = "worker" | "proxy";
export type VideoStatus = "pending" | "failed" | "running" | "completed" | "uploading" | "downloading";
}
}
declare namespace Illuminate {
export type CursorPaginator<TKey, TValue> = {
data: TKey extends string ? Record<TKey, TValue> : TValue[],
links: {
url: string | null,
label: string,
active: boolean,
}[],
meta: {
path: string,
per_page: number,
next_cursor: string | null,
next_page_url: string | null,
prev_cursor: string | null,
prev_page_url: string | null,
},
};
export type CursorPaginatorInterface<TKey, TValue> = Illuminate.CursorPaginator<TKey, TValue>;
export type LengthAwarePaginator<TKey, TValue> = {
data: TKey extends string ? Record<TKey, TValue> : TValue[],
links: {
url: string | null,
label: string,
active: boolean,
}[],
meta: {
total: number,
current_page: number,
first_page_url: string,
from: number | null,
last_page: number,
last_page_url: string,
next_page_url: string | null,
path: string,
per_page: number,
prev_page_url: string | null,
to: number | null,
},
};
export type LengthAwarePaginatorInterface<TKey, TValue> = Illuminate.LengthAwarePaginator<TKey, TValue>;
}
declare namespace Spatie {
namespace LaravelData {
export type CursorPaginatedDataCollection<TKey, TValue> = Illuminate.CursorPaginator<TKey, TValue>;
export type PaginatedDataCollection<TKey, TValue> = Illuminate.LengthAwarePaginator<TKey, TValue>;
}
}
